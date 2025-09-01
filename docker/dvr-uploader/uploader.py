#!/usr/bin/env python3
"""
DVR S3 Uploader Service
Monitors DVR recordings and uploads completed segments to S3
"""

import os
import time
import json
import logging
import threading
from pathlib import Path
from datetime import datetime
import boto3
from botocore.exceptions import ClientError
from botocore.config import Config
from boto3.s3.transfer import TransferConfig
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
import requests

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('dvr-uploader')

# Configuration from environment variables
S3_BUCKET = os.environ.get('S3_BUCKET', 'ef-streaming-recordings')
S3_REGION = os.environ.get('S3_REGION', 'eu-central-1')
S3_ACCESS_KEY = os.environ.get('S3_ACCESS_KEY')
S3_SECRET_KEY = os.environ.get('S3_SECRET_KEY')
S3_ENDPOINT = os.environ.get('S3_ENDPOINT')  # Optional for S3-compatible services
RECORDINGS_PATH = os.environ.get('RECORDINGS_PATH', '/dvr/recordings')
DELETE_AFTER_UPLOAD = os.environ.get('DELETE_AFTER_UPLOAD', 'true').lower() == 'true'
WEBHOOK_URL = os.environ.get('WEBHOOK_URL')  # Optional webhook for notifications
FILE_AGE_SECONDS = int(os.environ.get('FILE_AGE_SECONDS', '30'))  # Wait time before upload
MAX_CONCURRENT_UPLOADS = int(os.environ.get('MAX_CONCURRENT_UPLOADS', '5'))  # Max concurrent file uploads

# S3 client configuration with optimized connection pool
boto_config = Config(
    max_pool_connections=100,  # Increased from default 10 to support concurrent uploads
    retries={
        'max_attempts': 3,
        'mode': 'adaptive'
    },
    read_timeout=300,  # 5 minutes for large uploads
    connect_timeout=60
)

s3_config = {
    'region_name': S3_REGION,
    'config': boto_config
}

if S3_ACCESS_KEY and S3_SECRET_KEY:
    s3_config['aws_access_key_id'] = S3_ACCESS_KEY
    s3_config['aws_secret_access_key'] = S3_SECRET_KEY

if S3_ENDPOINT:
    s3_config['endpoint_url'] = S3_ENDPOINT

# Initialize S3 client with optimized config
s3_client = boto3.client('s3', **s3_config)

# Multipart transfer configuration optimized for 800MB files
transfer_config = TransferConfig(
    multipart_threshold=100 * 1024 * 1024,  # 100MB threshold
    multipart_chunksize=100 * 1024 * 1024,  # 100MB chunks (8 parts for 800MB)
    max_concurrency=5,  # 5 threads per file upload
    use_threads=True
)

# Track files being processed and upload concurrency
processing_files = set()
file_lock = threading.Lock()
upload_semaphore = threading.Semaphore(MAX_CONCURRENT_UPLOADS)

# Metrics tracking
upload_metrics = {
    'queue_depth': 0,
    'uploads_in_progress': 0,
    'uploads_completed': 0,
    'uploads_failed': 0,
    'total_bytes_uploaded': 0,
    'last_upload_speed_mbps': 0
}
metrics_lock = threading.Lock()


class DVRFileHandler(FileSystemEventHandler):
    """Handler for DVR file events"""
    
    def __init__(self):
        self.pending_files = {}
        self.check_thread = threading.Thread(target=self.check_pending_files, daemon=True)
        self.check_thread.start()
    
    def on_created(self, event):
        """Handle new file creation"""
        if event.is_directory:
            return
        
        if event.src_path.endswith(('.mp4', '.flv')):
            logger.info(f"New recording detected: {event.src_path}")
            with file_lock:
                self.pending_files[event.src_path] = time.time()
    
    def on_modified(self, event):
        """Handle file modification"""
        if event.is_directory:
            return
        
        if event.src_path.endswith(('.mp4', '.flv')):
            # Update the timestamp for pending files
            with file_lock:
                if event.src_path in self.pending_files:
                    self.pending_files[event.src_path] = time.time()
    
    def check_pending_files(self):
        """Check for files that haven't been modified recently"""
        while True:
            time.sleep(10)
            current_time = time.time()
            files_to_process = []
            
            with file_lock:
                queue_depth = len(self.pending_files)
                if queue_depth > 10:
                    logger.warning(f"Upload queue depth high: {queue_depth} files pending")
                
                for file_path, last_modified in list(self.pending_files.items()):
                    if current_time - last_modified > FILE_AGE_SECONDS:
                        if file_path not in processing_files:
                            files_to_process.append(file_path)
                            del self.pending_files[file_path]
                            processing_files.add(file_path)
            
            # Update metrics
            with metrics_lock:
                upload_metrics['queue_depth'] = len(self.pending_files) + len(processing_files)
            
            for file_path in files_to_process:
                threading.Thread(
                    target=process_file,
                    args=(file_path,),
                    daemon=True
                ).start()


def process_file(file_path):
    """Process and upload a recording file"""
    # Use semaphore to limit concurrent uploads
    with upload_semaphore:
        try:
            # Update metrics
            with metrics_lock:
                upload_metrics['uploads_in_progress'] += 1
            
            start_time = time.time()
            logger.info(f"Processing file: {file_path}")
            
            # Verify file exists and is not being written
            if not os.path.exists(file_path):
                logger.warning(f"File no longer exists: {file_path}")
                return
            
            # Get file size to ensure it's complete
            file_size = os.path.getsize(file_path)
            if file_size == 0:
                logger.warning(f"File is empty: {file_path}")
                return
        
            # Wait a moment and check size again to ensure writing is complete
            time.sleep(2)
            new_size = os.path.getsize(file_path)
            if new_size != file_size:
                logger.info(f"File still being written: {file_path}")
                # Re-add to pending
                with file_lock:
                    handler.pending_files[file_path] = time.time()
                    processing_files.discard(file_path)
                return
            
            # Parse the file path to extract metadata
            path_parts = Path(file_path).parts
            relative_path = Path(file_path).relative_to(RECORDINGS_PATH)
            
            # Generate S3 key with dvr/ prefix
            s3_key = f"dvr/{relative_path}"
            
            # Upload to S3
            logger.info(f"Uploading to S3: {s3_key}")
            upload_to_s3(file_path, s3_key)
            
            # Send webhook notification if configured
            if WEBHOOK_URL:
                notify_webhook(file_path, s3_key)
            
            # Delete local file if configured
            if DELETE_AFTER_UPLOAD:
                os.remove(file_path)
                logger.info(f"Deleted local file: {file_path}")
                
                # Clean up empty directories
                cleanup_empty_dirs(os.path.dirname(file_path))
            
            # Calculate upload speed
            elapsed_time = time.time() - start_time
            file_size_mb = os.path.getsize(file_path) / (1024 * 1024) if os.path.exists(file_path) else 0
            upload_speed = file_size_mb / elapsed_time if elapsed_time > 0 else 0
            
            with metrics_lock:
                upload_metrics['uploads_completed'] += 1
                upload_metrics['total_bytes_uploaded'] += file_size_mb * 1024 * 1024
                upload_metrics['last_upload_speed_mbps'] = upload_speed * 8
            
            logger.info(f"Successfully processed: {file_path} ({file_size_mb:.1f}MB in {elapsed_time:.1f}s, {upload_speed:.1f}MB/s)")
            
            except Exception as e:
            logger.error(f"Error processing file {file_path}: {e}")
            with metrics_lock:
                upload_metrics['uploads_failed'] += 1
        finally:
            with file_lock:
                processing_files.discard(file_path)
            with metrics_lock:
                upload_metrics['uploads_in_progress'] -= 1


def upload_to_s3(file_path, s3_key):
    """Upload file to S3 with optimized multipart support for large files"""
    try:
        # Determine content type
        content_type = 'video/mp4' if file_path.endswith('.mp4') else 'video/x-flv'
        
        # Get file stats for metadata
        file_stats = os.stat(file_path)
        file_size = file_stats.st_size
        
        # Metadata for the S3 object
        metadata = {
            'original-filename': os.path.basename(file_path),
            'upload-timestamp': datetime.utcnow().isoformat(),
            'file-size': str(file_size),
        }
        
        # Extract stream info from path if possible
        try:
            parts = Path(file_path).parts
            if len(parts) >= 4:
                app_name = parts[-4]
                stream_name = parts[-3]
                metadata['app'] = app_name
                metadata['stream'] = stream_name
        except:
            pass
        
        logger.info(f"Starting upload: {s3_key} ({file_size / (1024*1024):.1f}MB)")
        
        # Use optimized multipart upload for all files over 100MB
        if file_size > 100 * 1024 * 1024:  # 100MB threshold
            logger.info(f"Using optimized multipart upload ({file_size / (1024*1024):.1f}MB with {file_size / (100*1024*1024):.0f} parts)")
            
            # Upload using optimized multipart config
            s3_client.upload_file(
                file_path,
                S3_BUCKET,
                s3_key,
                ExtraArgs={
                    'ContentType': content_type,
                    'Metadata': metadata
                },
                Config=transfer_config  # Use global optimized config
            )
        else:
            # Regular upload for smaller files
            with open(file_path, 'rb') as f:
                s3_client.put_object(
                    Bucket=S3_BUCKET,
                    Key=s3_key,
                    Body=f,
                    ContentType=content_type,
                    Metadata=metadata
                )
        
        logger.info(f"Uploaded to S3: s3://{S3_BUCKET}/{s3_key}")
        
    except ClientError as e:
        logger.error(f"S3 upload failed: {e}")
        raise


def notify_webhook(file_path, s3_key):
    """Send webhook notification about uploaded file"""
    try:
        payload = {
            'event': 'dvr_uploaded',
            'file_path': file_path,
            's3_bucket': S3_BUCKET,
            's3_key': s3_key,
            's3_url': f"s3://{S3_BUCKET}/{s3_key}",
            'timestamp': datetime.utcnow().isoformat(),
        }
        
        # Extract stream info if possible
        try:
            parts = Path(file_path).parts
            if len(parts) >= 4:
                payload['app'] = parts[-4]
                payload['stream'] = parts[-3]
                payload['date'] = parts[-2]
        except:
            pass
        
        response = requests.post(
            WEBHOOK_URL,
            json=payload,
            timeout=10
        )
        response.raise_for_status()
        logger.info(f"Webhook notification sent for: {s3_key}")
        
    except Exception as e:
        logger.error(f"Webhook notification failed: {e}")


def cleanup_empty_dirs(directory):
    """Remove empty directories recursively"""
    try:
        # Don't delete the root recordings directory
        if directory == RECORDINGS_PATH:
            return
        
        # Check if directory is empty
        if os.path.isdir(directory) and not os.listdir(directory):
            os.rmdir(directory)
            logger.info(f"Removed empty directory: {directory}")
            
            # Recursively check parent
            parent = os.path.dirname(directory)
            if parent != RECORDINGS_PATH:
                cleanup_empty_dirs(parent)
    except Exception as e:
        logger.debug(f"Could not remove directory {directory}: {e}")


def scan_existing_files():
    """Scan for existing files on startup"""
    logger.info(f"Scanning existing files in {RECORDINGS_PATH}")
    
    for root, dirs, files in os.walk(RECORDINGS_PATH):
        for file in files:
            if file.endswith(('.mp4', '.flv')):
                file_path = os.path.join(root, file)
                
                # Check file age
                try:
                    file_stat = os.stat(file_path)
                    file_age = time.time() - file_stat.st_mtime
                    
                    # If file is old enough, process it
                    if file_age > FILE_AGE_SECONDS:
                        logger.info(f"Found existing file: {file_path}")
                        threading.Thread(
                            target=process_file,
                            args=(file_path,),
                            daemon=True
                        ).start()
                    else:
                        # Add to pending files
                        with file_lock:
                            handler.pending_files[file_path] = file_stat.st_mtime
                        logger.info(f"Found recent file, adding to watch: {file_path}")
                        
                except Exception as e:
                    logger.error(f"Error checking file {file_path}: {e}")


def print_metrics():
    """Print upload metrics periodically"""
    while True:
        time.sleep(60)  # Print every minute
        with metrics_lock:
            logger.info(
                f"Upload Metrics - Queue: {upload_metrics['queue_depth']}, "
                f"In Progress: {upload_metrics['uploads_in_progress']}, "
                f"Completed: {upload_metrics['uploads_completed']}, "
                f"Failed: {upload_metrics['uploads_failed']}, "
                f"Last Speed: {upload_metrics['last_upload_speed_mbps']:.1f} Mbps"
            )
            
            # Alert if queue is getting too deep
            if upload_metrics['queue_depth'] > 20:
                logger.error(f"CRITICAL: Upload queue depth is {upload_metrics['queue_depth']} - falling behind!")


if __name__ == "__main__":
    logger.info("DVR S3 Uploader Service starting...")
    logger.info(f"Configuration: MAX_CONCURRENT_UPLOADS={MAX_CONCURRENT_UPLOADS}, FILE_AGE_SECONDS={FILE_AGE_SECONDS}")
    
    # Verify S3 configuration
    if not S3_BUCKET:
        logger.error("S3_BUCKET environment variable is required")
        exit(1)
    
    # Create recordings directory if it doesn't exist
    os.makedirs(RECORDINGS_PATH, exist_ok=True)
    
    # Start metrics reporting thread
    metrics_thread = threading.Thread(target=print_metrics, daemon=True)
    metrics_thread.start()
    
    # Test S3 connection
    try:
        s3_client.head_bucket(Bucket=S3_BUCKET)
        logger.info(f"Successfully connected to S3 bucket: {S3_BUCKET}")
    except ClientError as e:
        error_code = e.response['Error']['Code']
        if error_code == '404':
            logger.error(f"S3 bucket does not exist: {S3_BUCKET}")
        else:
            logger.error(f"Failed to connect to S3: {e}")
        # Continue anyway - bucket might be created later
    
    # Set up file system monitoring
    handler = DVRFileHandler()
    observer = Observer()
    observer.schedule(handler, RECORDINGS_PATH, recursive=True)
    
    # Scan existing files
    scan_existing_files()
    
    # Start monitoring
    observer.start()
    logger.info(f"Monitoring {RECORDINGS_PATH} for DVR recordings...")
    
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
        logger.info("DVR S3 Uploader Service stopped")
    
    observer.join()