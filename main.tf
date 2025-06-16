# main.tf

terraform {
  required_providers {
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.37.0"
    }
    helm = {
      source  = "hashicorp/helm"
      version = "~> 2.17.0"
    }
    null = {
      source  = "hashicorp/null"
      version = "~> 3.2.0"
    }
  }
}

provider "kubernetes" {
  config_path    = "~/.kube/config"
  config_context = "k3d-otest"
}

provider "helm" {
  kubernetes {
    config_path    = "~/.kube/config"
    config_context = "k3d-otest"
  }
}

resource "null_resource" "create_k3d_cluster" {
  provisioner "local-exec" {
    command = "k3d cluster create otest --agents 3"
  }

  triggers = {
    always_run = timestamp()
  }
}

resource "helm_release" "cert_manager" {
  depends_on       = [null_resource.create_k3d_cluster]
  name             = "cert-manager"
  namespace        = "cert-manager"
  create_namespace = true

  repository = "https://charts.jetstack.io"
  chart      = "cert-manager"
  version    = "v1.17.2"

  set {
    name  = "installCRDs"
    value = "true"
  }

  # lifecycle {
  #   prevent_destroy = true
  # }
}

resource "helm_release" "otel_operator" {
  depends_on       = [null_resource.create_k3d_cluster, helm_release.cert_manager]
  name             = "opentelemetry-operator"
  namespace        = "opentelemetry-operator-system"
  create_namespace = true

  repository = "https://open-telemetry.github.io/opentelemetry-helm-charts"
  chart      = "opentelemetry-operator"
  version    = "0.90.03"

  # lifecycle {
  #   prevent_destroy = true
  # }
}

resource "null_resource" "apply_manifests" {
  depends_on = [null_resource.create_k3d_cluster]

  provisioner "local-exec" {
    command = <<EOT
      kubectl apply -f manifests/nginx-config.yaml
      kubectl apply -f manifests/nginx.yaml
      kubectl apply -f manifests/redis.yaml
      # Exporter manifests moved to manifests/exporters/ directory for comparison
      kubectl apply -f manifests/exporters/redis-exporter.yaml
      kubectl apply -f manifests/exporters/redis-exporter-service.yaml
      kubectl apply -f manifests/exporters/nginx-exporter-service.yaml
    EOT
  }
}

resource "null_resource" "wait_for_crds" {
  depends_on = [helm_release.otel_operator]

  provisioner "local-exec" {
    command = "sleep 5" # Wait for CRDs to be installed
  }
}

# resource "null_resource" "prometheus_config" {
#   depends_on = [null_resource.create_k3d_cluster]
#
#   provisioner "local-exec" {
#     command = <<EOT
#       kubectl create namespace monitoring --dry-run=client -o yaml | kubectl apply -f -
#       kubectl apply -f manifests/prometheus-config.yaml
#     EOT
#   }
# }

resource "null_resource" "datadog_env_configmap" {
  depends_on = [helm_release.otel_operator]
  provisioner "local-exec" {
    command = <<EOT
      # Create ConfigMap from .env file
      kubectl create configmap datadog-env --from-env-file=.env -n opentelemetry-operator-system --dry-run=client -o yaml | kubectl apply -f -
    EOT
  }
}

resource "null_resource" "otel_collector" {
  depends_on = [null_resource.wait_for_crds, null_resource.datadog_env_configmap]
  provisioner "local-exec" {
    command = "kubectl apply -f manifests/otel-collector.yaml"
  }
}

# resource "helm_release" "prometheus" {
#   depends_on       = [null_resource.create_k3d_cluster, null_resource.prometheus_config]
#   name             = "prometheus"
#   namespace        = "monitoring"
#
#   repository = "https://prometheus-community.github.io/helm-charts"
#   chart      = "prometheus"
#   version    = "27.20.0"
#
#   set {
#     name  = "server.persistentVolume.enabled"
#     value = "false"
#   }
#
#   set {
#     name  = "alertmanager.enabled"
#     value = "false"
#   }
#   
#   values = [
#     <<-EOT
#     server:
#       configPath: /etc/prometheus/prometheus.yml
#       extraConfigmapMounts:
#         - name: prometheus-config
#           mountPath: /etc/prometheus
#           configMap: prometheus-config
#           readOnly: true
#     EOT
#   ]
# }
#


resource "null_resource" "delete_k3d_cluster" {
  depends_on = [helm_release.otel_operator, helm_release.cert_manager]
  triggers = {
    always_run = timestamp()
  }

  provisioner "local-exec" {
    when    = destroy
    command = "k3d cluster delete otest"
  }
}
