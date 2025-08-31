#!/bin/bash

echo "DVR Testing Script"
echo "=================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if services are running
echo -e "${YELLOW}Step 1: Checking services...${NC}"
if curl -s http://localhost:1985/api/v1/versions > /dev/null; then
    echo -e "${GREEN}✓ SRS API is accessible${NC}"
else
    echo -e "${RED}✗ SRS API is not accessible${NC}"
    echo "Please ensure the origin-srs container is running"
    exit 1
fi

# Check DVR configuration
echo -e "\n${YELLOW}Step 2: Checking DVR configuration...${NC}"
DVR_CONFIG=$(curl -s http://localhost:1985/api/v1/raw?remark=config | grep -A 10 "dvr {")
if echo "$DVR_CONFIG" | grep -q "enabled on"; then
    echo -e "${GREEN}✓ DVR is enabled${NC}"
else
    echo -e "${RED}✗ DVR is not enabled${NC}"
fi

# Check DVR recordings directory
echo -e "\n${YELLOW}Step 3: Checking DVR recordings directory...${NC}"
sail exec origin-srs ls -la /dvr/recordings 2>/dev/null || docker-compose exec origin-srs ls -la /dvr/recordings 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ DVR recordings directory is accessible${NC}"
else
    echo -e "${YELLOW}! DVR recordings directory not accessible yet${NC}"
fi

# Test publishing a stream
echo -e "\n${YELLOW}Step 4: Instructions for testing DVR recording:${NC}"
echo "1. Start a test stream using FFmpeg or OBS:"
echo "   FFmpeg example:"
echo "   ffmpeg -re -f lavfi -i testsrc=size=1280x720:rate=30 -f lavfi -i sine=frequency=1000 \\"
echo "          -c:v libx264 -preset ultrafast -b:v 1000k -g 60 \\"
echo "          -c:a aac -b:a 128k \\"
echo "          -f flv rtmp://localhost:1935/live/test"
echo ""
echo "2. DVR will automatically start recording when stream begins"
echo "3. Files will be saved to: /dvr/recordings/live/test/[date]/[time]_[timestamp].mp4"
echo "4. Each segment will be 10 minutes (600 seconds)"
echo "5. S3 uploader will upload completed segments after 30 seconds of inactivity"
echo ""

# Monitor DVR activity
echo -e "\n${YELLOW}Step 5: Monitoring DVR activity:${NC}"
echo "To monitor DVR recordings in real-time:"
echo "  sail exec origin-srs watch -n 2 'find /dvr/recordings -type f -name \"*.mp4\" -o -name \"*.flv\" | head -20'"
echo ""
echo "To check S3 uploader logs:"
echo "  sail logs -f dvr-uploader"
echo ""
echo "To check DVR callbacks:"
echo "  sail logs laravel.test | grep 'DVR callback'"
echo ""

# Check environment variables for S3
echo -e "\n${YELLOW}Step 6: Checking S3 configuration:${NC}"
if [ -f .env ]; then
    if grep -q "AWS_ACCESS_KEY_ID=" .env && grep -q "AWS_SECRET_ACCESS_KEY=" .env; then
        echo -e "${GREEN}✓ AWS credentials are configured in .env${NC}"
    else
        echo -e "${YELLOW}! AWS credentials not found in .env${NC}"
        echo "  Add the following to your .env file:"
        echo "    AWS_ACCESS_KEY_ID=your_access_key"
        echo "    AWS_SECRET_ACCESS_KEY=your_secret_key"
        echo "    AWS_DEFAULT_REGION=eu-central-1"
        echo "    AWS_BUCKET=ef-streaming-recordings"
    fi
fi

echo -e "\n${GREEN}DVR setup is complete!${NC}"
echo "The DVR will automatically:"
echo "• Start recording when any stream begins publishing"
echo "• Split recordings into 10-minute segments"
echo "• Wait for keyframes before splitting"
echo "• Upload completed segments to S3"
echo "• Delete local files after successful upload"
echo "• Send webhooks to Laravel for tracking"