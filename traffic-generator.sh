#!/bin/bash

# Set the base URL for the PHP app
BASE_URL="http://localhost:8021"

# Set the number of requests to send
NUM_REQUESTS=50

# Set the delay between requests (in seconds)
DELAY=0.2

# Target endpoint with parameter
ENDPOINT="/datetime?name=test-query"

echo "Starting traffic generation to PHP app..."
echo "Will send $NUM_REQUESTS requests to $BASE_URL$ENDPOINT with a $DELAY second delay between requests"
echo "Press Ctrl+C to stop"
echo ""

# Function to send a request
send_request() {
  echo "Sending request $1 of $NUM_REQUESTS to $BASE_URL$ENDPOINT"
  curl -s "$BASE_URL$ENDPOINT" > /dev/null
  
  # Print response every 10th request
  if [ $(($1 % 10)) -eq 0 ]; then
    echo "Sample response:"
    curl -s "$BASE_URL$ENDPOINT"
    echo ""
  fi
}

# Main loop
for ((i=1; i<=$NUM_REQUESTS; i++)); do
  send_request $i
  
  # Wait for the specified delay
  sleep $DELAY
done

echo "Traffic generation complete!"
