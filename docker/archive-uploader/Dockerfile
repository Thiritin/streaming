FROM python:3.11-slim

WORKDIR /app

RUN pip install --no-cache-dir boto3

# Mirrors the transcoder's HLS segments to S3 and maintains the per-hour index
# playlists recordings are cut from. See docs/dvr-archive-plan.md.
COPY archive_uploader.py /app/

RUN mkdir -p /var/lib/dvr-archive

CMD ["python", "-u", "archive_uploader.py"]
