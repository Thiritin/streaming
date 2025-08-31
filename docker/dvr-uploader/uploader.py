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
UPLOAD_DELAY_SECONDS = int(os.environ.get('UPLOAD_DELAY_SECONDS', '5'))  # Delay between uploads
MAX_UPLOAD_RATE_MBPS = float(os.environ.get('MAX_UPLOAD_RATE_MBPS', '5'))  # Max upload rate in MB/s

# S3 client configuration
s3_config = {
    'region_name': S3_REGION,
}

if S3_ACCESS_KEY and S3_SECRET_KEY:
    s3_config['aws_access_key_id'] = S3_ACCESS_KEY
    s3_config['aws_secret_access_key'] = S3_SECRET_KEY

if S3_ENDPOINT:
    s3_config['endpoint_url'] = S3_ENDPOINT

# Initialize S3 client
s3_client = boto3.client('s3', **s3_config)

# Track files being processed
processing_files = set()
file_lock = threading.Lock()


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
                for file_path, last_modified in list(self.pending_files.items()):
                    if current_time - last_modified > FILE_AGE_SECONDS:
                        if file_path not in processing_files:
                            files_to_process.append(file_path)
                            del self.pending_files[file_path]
                            processing_files.add(file_path)
            
            for file_path in files_to_process:
                threading.Thread(
                    target=process_file,
                    args=(file_path,),
                    daemon=True
                ).start()


def process_file(file_path):
    """Process and upload a recording file"""
    try:
        # Add delay between uploads to reduce system impact
        time.sleep(UPLOAD_DELAY_SECONDS)
        
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
        
        logger.info(f"Successfully processed: {file_path}")
        
    except Exception as e:
        logger.error(f"Error processing file {file_path}: {e}")
    finally:
        with file_lock:
            processing_files.discard(file_path)


def upload_to_s3(file_path, s3_key):
    """Upload file to S3 with multipart support for large files"""
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
        
        # Use multipart upload for files larger than 50MB
        if file_size > 50 * 1024 * 1024:  # 50MB threshold
            logger.info(f"Using multipart upload for large file ({file_size / (1024*1024):.1f}MB)")
            
            # Configure multipart transfer with conservative settings to avoid impacting FFmpeg
            from boto3.s3.transfer import TransferConfig
            config = TransferConfig(
                multipart_threshold=1024 * 25,  # 25MB
                max_concurrency=2,  # Reduced from 10 to minimize CPU/network impact
                multipart_chunksize=1024 * 10,  # Smaller 10MB chunks for smoother uploads
                use_threads=True
                # Note: max_bandwidth might not be available in all boto3 versions
            )
            
            # Upload using multipart
            s3_client.upload_file(
                file_path,
                S3_BUCKET,
                s3_key,
                ExtraArgs={
                    'ContentType': content_type,
                    'Metadata': metadata
                },
                Config=config
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


if __name__ == "__main__":
    logger.info("DVR S3 Uploader Service starting...")
    
    # Verify S3 configuration
    if not S3_BUCKET:
        logger.error("S3_BUCKET environment variable is required")
        exit(1)
    
    # Create recordings directory if it doesn't exist
    os.makedirs(RECORDINGS_PATH, exist_ok=True)
    
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