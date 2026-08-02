FROM python:3.11-slim

WORKDIR /app

# Install dependencies
RUN pip install --no-cache-dir \
    boto3 \
    watchdog \
    requests

# Two entrypoints share this image. uploader.py handles the SRS MP4 DVR; the
# archive uploader mirrors HLS segments and maintains the hour indexes. They watch
# different volumes and are deployed as separate containers, so the MP4 path can
# keep running as a cold backup while the segment archive proves itself.
COPY uploader.py archive_uploader.py /app/

RUN mkdir -p /dvr/recordings /var/lib/dvr-archive

CMD ["python", "-u", "uploader.py"]