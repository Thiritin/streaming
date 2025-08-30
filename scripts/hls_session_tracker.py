#!/usr/bin/env python3
"""
HLS Session Tracker for EF Streaming
Tracks HLS session activity from NGINX logs and reports to Laravel backend
"""

import time
import requests
import json
import subprocess
import threading
import logging
import os
import sys
from datetime import datetime, timedelta
from collections import defaultdict
from typing import Dict, Set, Optional

# Configuration
ACTIVITY_LOG = os.getenv('HLS_ACTIVITY_LOG', '/var/log/nginx/hls_activity.log')
SESSION_TIMEOUT = int(os.getenv('SESSION_TIMEOUT', '60'))  # seconds
CHECK_INTERVAL = int(os.getenv('CHECK_INTERVAL', '10'))    # seconds
API_ENDPOINT = os.getenv('API_ENDPOINT', 'http://localhost:8000/api/hls')
API_KEY = os.getenv('API_KEY', '')  # Shared secret for API authentication

# Logging setup
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler('/var/log/hls_tracker.log')
    ]
)
logger = logging.getLogger('hls_tracker')


class SessionInfo:
    """Container for session information"""
    def __init__(self, session_id: str, ip: str, stream: str, started_at: datetime):
        self.session_id = session_id
        self.ip = ip
        self.stream = stream
        self.started_at = started_at
        self.last_seen = started_at
        self.segments_watched = 0
        self.qualities_used = set()
        
    def update(self, timestamp: datetime, quality: Optional[str] = None):
        """Update session activity"""
        self.last_seen = timestamp
        self.segments_watched += 1
        if quality:
            self.qualities_used.add(quality)
    
    @property
    def duration_seconds(self) -> float:
        """Get session duration in seconds"""
        return (self.last_seen - self.started_at).total_seconds()
    
    def to_dict(self) -> dict:
        """Convert to dictionary for API reporting"""
        return {
            'session': self.session_id,
            'ip': self.ip,
            'stream': self.stream,
            'started_at': self.started_at.isoformat(),
            'last_seen': self.last_seen.isoformat(),
            'duration_seconds': self.duration_seconds,
            'segments_watched': self.segments_watched,
            'qualities_used': list(self.qualities_used)
        }


class HLSSessionTracker:
    """Main tracker class for HLS sessions"""
    
    def __init__(self):
        self.sessions: Dict[str, SessionInfo] = {}
        self.reported_ended: Set[str] = set()
        self.lock = threading.Lock()
        self.running = True
        self.api_headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
        if API_KEY:
            self.api_headers['X-API-Key'] = API_KEY
    
    def process_log_line(self, line: str) -> None:
        """Process a single log line from NGINX"""
        try:
            # Parse log format: $remote_addr|$arg_session|$time_iso8601|$uri|$status|$arg_stream
            parts = line.strip().split('|')
            if len(parts) < 5:
                return
                
            ip, session_id, timestamp_str, uri, status = parts[:5]
            stream = parts[5] if len(parts) > 5 else None
            
            # Skip invalid entries
            if not session_id or session_id == '-' or status != '200':
                return
            
            # Parse timestamp
            try:
                timestamp = datetime.fromisoformat(timestamp_str.replace('Z', '+00:00'))
            except:
                timestamp = datetime.now()
            
            # Extract stream name and quality from URI
            if not stream and '/' in uri:
                path_parts = uri.split('/')
                if len(path_parts) >= 3:
                    stream_part = path_parts[2]
                    # Remove file extensions
                    stream = stream_part.replace('.m3u8', '').replace('.ts', '')
                    
                    # Extract quality if present
                    quality = None
                    for q in ['_fhd', '_hd', '_sd', '_ld']:
                        if q in stream:
                            quality = q.replace('_', '')
                            stream = stream.replace(q, '')
                            break
            
            with self.lock:
                # New session?
                if session_id not in self.sessions:
                    self.session_started(session_id, ip, stream, timestamp)
                    self.sessions[session_id] = SessionInfo(session_id, ip, stream, timestamp)
                else:
                    # Update existing session
                    self.sessions[session_id].update(timestamp, quality if 'quality' in locals() else None)
                
                # Remove from ended list if it's back
                self.reported_ended.discard(session_id)
                
        except Exception as e:
            logger.error(f"Error processing log line: {e}")
    
    def session_started(self, session_id: str, ip: str, stream: str, timestamp: datetime) -> None:
        """Report new session start to API"""
        logger.info(f"Session started: {session_id} from {ip} watching {stream}")
        
        try:
            response = requests.post(
                f"{API_ENDPOINT}/session/start",
                json={
                    'session': session_id,
                    'ip': ip,
                    'stream': stream,
                    'timestamp': timestamp.isoformat()
                },
                headers=self.api_headers,
                timeout=5
            )
            response.raise_for_status()
        except Exception as e:
            logger.error(f"Failed to report session start: {e}")
    
    def session_ended(self, session: SessionInfo) -> None:
        """Report session end to API"""
        if session.session_id in self.reported_ended:
            return
            
        logger.info(f"Session ended: {session.session_id} (duration: {session.duration_seconds:.0f}s, segments: {session.segments_watched})")
        
        try:
            response = requests.post(
                f"{API_ENDPOINT}/session/end",
                json=session.to_dict(),
                headers=self.api_headers,
                timeout=5
            )
            response.raise_for_status()
            self.reported_ended.add(session.session_id)
        except Exception as e:
            logger.error(f"Failed to report session end: {e}")
    
    def heartbeat(self, session: SessionInfo) -> None:
        """Send heartbeat for active session"""
        try:
            response = requests.post(
                f"{API_ENDPOINT}/session/heartbeat",
                json={
                    'session': session.session_id,
                    'stream': session.stream,
                    'timestamp': session.last_seen.isoformat(),
                    'segments_watched': session.segments_watched
                },
                headers=self.api_headers,
                timeout=5
            )
            response.raise_for_status()
        except Exception as e:
            logger.debug(f"Failed to send heartbeat: {e}")
    
    def check_timeouts(self) -> None:
        """Check for timed out sessions"""
        now = datetime.now()
        timeout_threshold = now - timedelta(seconds=SESSION_TIMEOUT)
        
        with self.lock:
            timed_out = []
            active = []
            
            for session_id, session in list(self.sessions.items()):
                if session.last_seen < timeout_threshold:
                    timed_out.append(session)
                    del self.sessions[session_id]
                else:
                    active.append(session)
            
            # Report ended sessions
            for session in timed_out:
                self.session_ended(session)
            
            # Send heartbeats for active sessions
            for session in active:
                # Send heartbeat every 30 seconds
                if (now - session.last_seen).total_seconds() < 30:
                    self.heartbeat(session)
            
            # Log statistics
            if len(self.sessions) > 0 or len(timed_out) > 0:
                logger.info(f"Active sessions: {len(self.sessions)}, Timed out: {len(timed_out)}")
    
    def tail_log(self) -> None:
        """Tail the NGINX log file and process new lines"""
        logger.info(f"Starting to tail log file: {ACTIVITY_LOG}")
        
        # Wait for log file to exist
        while not os.path.exists(ACTIVITY_LOG) and self.running:
            logger.warning(f"Log file {ACTIVITY_LOG} not found, waiting...")
            time.sleep(5)
        
        try:
            # Start tailing from end of file
            p = subprocess.Popen(
                ['tail', '-F', '-n', '0', ACTIVITY_LOG],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True
            )
            
            while self.running:
                line = p.stdout.readline()
                if line:
                    self.process_log_line(line)
                else:
                    time.sleep(0.1)
                    
        except Exception as e:
            logger.error(f"Error tailing log: {e}")
        finally:
            if 'p' in locals():
                p.terminate()
    
    def run(self) -> None:
        """Main loop"""
        logger.info(f"Starting HLS session tracker (timeout: {SESSION_TIMEOUT}s, check interval: {CHECK_INTERVAL}s)")
        
        # Start log tailing in background thread
        tail_thread = threading.Thread(target=self.tail_log, daemon=True)
        tail_thread.start()
        
        # Check for timeouts periodically
        try:
            while self.running:
                time.sleep(CHECK_INTERVAL)
                self.check_timeouts()
        except KeyboardInterrupt:
            logger.info("Shutting down...")
            self.shutdown()
    
    def shutdown(self) -> None:
        """Clean shutdown"""
        self.running = False
        
        # Report all remaining sessions as ended
        with self.lock:
            for session in self.sessions.values():
                self.session_ended(session)
        
        logger.info("Tracker shut down")


def main():
    """Main entry point"""
    # Verify configuration
    if not API_ENDPOINT:
        logger.error("API_ENDPOINT environment variable not set")
        sys.exit(1)
    
    # Create and run tracker
    tracker = HLSSessionTracker()
    
    try:
        tracker.run()
    except Exception as e:
        logger.error(f"Fatal error: {e}", exc_info=True)
        sys.exit(1)


if __name__ == "__main__":
    main()