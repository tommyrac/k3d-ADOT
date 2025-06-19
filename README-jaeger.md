# Jaeger Tracing Implementation

This document describes the Jaeger tracing implementation in the k3d-ADOT cluster.

## Overview

Jaeger has been implemented as a self-hosted tracing backend for the OpenTelemetry Collector. The setup includes:

1. Elasticsearch as the storage backend for Jaeger
2. Jaeger All-in-One deployment for simplicity
3. OpenTelemetry Collector configured to export traces to Jaeger
4. NodePort service to access the Jaeger UI

## Architecture

```
┌────────────────┐     ┌────────────────┐     ┌────────────────┐
│   Application  │────▶│  OTEL Collector│────▶│     Jaeger     │
└────────────────┘     └────────────────┘     └────────────────┘
                                                      │
                                                      ▼
                                             ┌────────────────┐
                                             │  Elasticsearch │
                                             └────────────────┘
```

## Components

### Elasticsearch

Elasticsearch serves as the storage backend for Jaeger. It's deployed in the `observability` namespace with minimal resource requirements suitable for a development environment.

### Jaeger

Jaeger is deployed in "all-in-one" mode, which combines the collector, query, and storage components into a single deployment. This mode is suitable for development and testing environments.

Key features:
- OTLP protocol support (gRPC and HTTP)
- Integration with Elasticsearch for storage
- UI accessible via NodePort

### OpenTelemetry Collector

The OpenTelemetry Collector has been configured to export traces to Jaeger using the OTLP exporter. This allows traces collected from applications to be sent to Jaeger for visualization and analysis.

## Accessing the Jaeger UI

The Jaeger UI is accessible via NodePort at port 30686. You can access it at:

```
http://localhost:30686
```

## Sending Traces to Jaeger

Applications should send traces to the OpenTelemetry Collector, which will forward them to Jaeger. The collector accepts traces via the OTLP protocol at:

- gRPC: `collector.opentelemetry-operator-system.svc.cluster.local:4317`
- HTTP: `collector.opentelemetry-operator-system.svc.cluster.local:4318`

## Terraform Management

All components are managed via Terraform and can be deployed or updated using:

```bash
terraform apply
```

The implementation includes:
- Helm charts for Jaeger and Elasticsearch
- Custom values for Jaeger configuration
- Service definitions for UI access
- OpenTelemetry Collector configuration for trace export
