#!/bin/bash

# Create the namespace if it doesn't exist
kubectl create namespace opentelemetry-operator-system --dry-run=client -o yaml | kubectl apply -f -

# Create ConfigMap from .env file
kubectl create configmap datadog-env --from-env-file=.env -n opentelemetry-operator-system --dry-run=client -o yaml | kubectl apply -f -

echo "ConfigMap 'datadog-env' created in namespace 'opentelemetry-operator-system'"
