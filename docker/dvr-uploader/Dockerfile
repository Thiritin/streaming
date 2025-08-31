FROM python:3.11-slim

WORKDIR /app

# Install dependencies
RUN pip install --no-cache-dir \
    boto3 \
    watchdog \
    requests

# Copy the uploader script
COPY uploader.py /app/

# Create recordings directory
RUN mkdir -p /dvr/recordings

CMD ["python", "-u", "uploader.py"]