#!/bin/bash

# Set the base URL for the PHP app
BASE_URL="http://localhost:8021"

# Set the number of requests to send
NUM_REQUESTS=20

# Set the delay between requests (in seconds)
DELAY=0.5

# Available endpoints
ENDPOINTS=(
  "/"
  "/outgoing-http-call"
  "/aws-sdk-call"
)

echo "Starting traffic generation to PHP app..."
echo "Will send $NUM_REQUESTS requests to each endpoint with a $DELAY second delay between requests"
echo "Press Ctrl+C to stop"
echo ""

# Function to send a request to a specific endpoint
send_request() {
  local endpoint=$1
  echo "Sending request to $BASE_URL$endpoint"
  curl -s "$BASE_URL$endpoint" > /dev/null &
}

# Main loop
for ((i=1; i<=$NUM_REQUESTS; i++)); do
  echo "Request batch $i of $NUM_REQUESTS"
  
  # Send requests to all endpoints in parallel
  for endpoint in "${ENDPOINTS[@]}"; do
    send_request "$endpoint"
  done
  
  # Wait for the specified delay
  sleep $DELAY
done

echo "Traffic generation complete!"
