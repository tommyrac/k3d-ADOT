<?php
namespace App\Controller;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\LoggerHolder;

use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;

use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\Aws\AwsSdkInstrumentation;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

LoggerHolder::set(
    new Logger('grpc', [new StreamHandler('php://stderr')])
);

class AwsSdkInstrumentationController
{
    private Request $request;

    public function __construct()
    {
        $this->request = Request::createFromGlobals();
    }

    #[Route('/')]
    public function home(): Response
    {
        return new Response(
            '<html><body>AWS SDK Instrumentation Sample App Running!</body></html>'
        );
    }
    
    #[Route('/datetime')]
    public function dateTime(): Response
    {
        $name = $this->request->query->get('name');
        
        // Create a span for this request
        $endpoint = $this->getEndpoint();
        $transport = (new GrpcTransportFactory())->create($endpoint . OtlpUtil::method(Signals::TRACE));
        $exporter = new SpanExporter($transport);
        
        // Initialize Span Processor, X-Ray ID generator, Tracer Provider, and Propagator
        $spanProcessor = new SimpleSpanProcessor($exporter);
        $idGenerator = new IdGenerator();
        $tracerProvider = new TracerProvider($spanProcessor, null, null, null, $idGenerator);
        $tracer = $tracerProvider->getTracer('io.opentelemetry.contrib.php');
        
        // Create and activate span
        $span = $tracer
                ->spanBuilder('datetime-endpoint')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
        $scope = $span->activate();
        
        try {
            // Add attributes to the span
            $span->setAttribute('request.name', $name ?? 'not-provided');
            
            // Process the request
            if ($name === 'test-query') {
                $currentDateTime = new \DateTime();
                $response = new JsonResponse([
                    'status' => 'success',
                    'datetime' => $currentDateTime->format('Y-m-d H:i:s'),
                    'timestamp' => $currentDateTime->getTimestamp(),
                ]);
            } else {
                $response = new JsonResponse([
                    'status' => 'error',
                    'message' => 'Invalid name parameter. Use name=test-query',
                ], 400);
            }
            
            // Add response info to span
            $span->setAttribute('response.status_code', $response->getStatusCode());
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
            
            return $response;
        } catch (\Exception $e) {
            // Record error in span
            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
            
            return new JsonResponse([
                'status' => 'error',
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        } finally {
            // End the span and scope
            $span->end();
            $scope->detach();
        }
    }

    private function convertOtelTraceIdToXrayFormat(String $otelTraceId) : String
    {
        $xrayTraceID = sprintf(
            "1-%s-%s",
            substr($otelTraceId, 0, 8),
            substr($otelTraceId, 8)
        );

        return $xrayTraceID;
    }

    #[Route('/outgoing-http-call')]
    public function outgoingHttpCall(): Response
    {
        /*
        otel:4317 endpoint corresponds to the collector endpoint in docker-compose 
        If running this sample app locally, set the endpoint to correspond to the endpoint 
        of your collector instance. 
        */
        $endpoint = $this->getEndpoint();
        $transport = (new GrpcTransportFactory())->create($endpoint . OtlpUtil::method(Signals::TRACE));
        $exporter = new SpanExporter($transport);

        // Initialize Span Processor, X-Ray ID generator, Tracer Provider, and Propagator
        $spanProcessor = new SimpleSpanProcessor($exporter);
        $idGenerator = new IdGenerator();
        $tracerProvider = new TracerProvider($spanProcessor, null, null, null, $idGenerator);
        $propagator = new Propagator();
        $tracer = $tracerProvider->getTracer('io.opentelemetry.contrib.php');
        $carrier = [];
        $traceId = "";


        try {
            // Create and activate root span
            $root = $tracer
                    ->spanBuilder('outgoing-http-call')
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->startSpan();
            $rootScope = $root->activate();

            $httpSpan = $tracer
                    ->spanBuilder('get-request')
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->startSpan();
            $httpScope = $httpSpan->activate();

            // Make HTTP request
            $client = HttpClient::create(); 

            $awsHttpUrl = 'https://aws.amazon.com/';

            $response = $client->request(
                'GET',
                $awsHttpUrl
            );

            $propagator->inject($carrier);

            $root->setAttributes([
                "http.method" => $this->request->getMethod(),
                "http.url" => $this->request->getUri(),
                "http.status_code" => $response->getStatusCode()
            ]);

            $httpSpan->setAttributes([
                "http.method" => $this->request->getMethod(),
                "http.url" => $awsHttpUrl,
                "http.status_code" => $response->getStatusCode()
            ]);

            $traceId = $this->convertOtelTraceIdToXrayFormat(
                $root->getContext()->getTraceId()
            );
        } finally {
            $httpSpan->end();
            $httpScope->detach();

            $root->end();
            $rootScope->detach();

            $tracerProvider->shutdown();
        }
        
        return new JsonResponse(
            ['traceId' => $traceId]
        );
    }

    #[Route('/aws-sdk-call')]
    public function awsSdkCall(): Response
    {
        /*
            otel:4317 endpoint corresponds to the collector endpoint in docker-compose 
            If running this sample app locally, set the endpoint to correspond to the endpoint 
            of your collector instance. 
        */
        $endpoint = $this->getEndpoint();
        $transport = (new GrpcTransportFactory())->create($endpoint . OtlpUtil::method(Signals::TRACE));
        $exporter = new SpanExporter($transport);

        // Initialize Span Processor, X-Ray ID generator, Tracer Provider, and Propagator
        $spanProcessor = new SimpleSpanProcessor($exporter);
        $idGenerator = new IdGenerator();
        $tracerProvider = new TracerProvider($spanProcessor, null, null, null, $idGenerator);
        $propagator = new Propagator();

        // Create new instance of AWS SDK Instrumentation class
        $awssdkinstrumentation = new  AwsSdkInstrumentation();

        // Configure AWS SDK Instrumentation with Propagator and set Tracer Provider (created above)
        $awssdkinstrumentation->setPropagator($propagator);
        $awssdkinstrumentation->setTracerProvider($tracerProvider);
        $traceId = "";

        // Create and activate root span
        $root = $awssdkinstrumentation
                ->getTracer()
                ->spanBuilder('AwsSDKInstrumentation')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
        $rootScope = $root->activate();

        $root->setAttributes([
            "http.method" => $this->request->getMethod(),
            "http.url" => $this->request->getUri(),
        ]);

        // Initialize all AWS Client instances
        $s3Client = new S3Client([
            'region' => 'us-west-2',
            'version' => '2006-03-01'
        ]);

        // Pass client instances to AWS SDK
        $awssdkinstrumentation->instrumentClients([$s3Client]);

        // Activate Instrumentation -- all AWS Client calls will be instrumented
        $awssdkinstrumentation->activate();

        // Make S3 client call
        try{
            $result = $s3Client->listBuckets();

            echo $result['Body'] . "\n";

            $root->setAttributes([
                'http.status_code' => $result['@metadata']['statusCode'],
            ]);

            $traceId = $this->convertOtelTraceIdToXrayFormat(
                $root->getContext()->getTraceId()
            );

        } catch (AwsException $e){
            $root->recordException($e);
        } finally {
            // End the root span after all the calls to the AWS SDK have been made
            $root->end();
            $rootScope->detach();

            $tracerProvider->shutdown();
        }

        return new JsonResponse(
            ['traceId' => $traceId]
        );
    }

    private function getEndpoint(): string
    {
        if (Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT)) {
            return Configuration::getString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT);
        }
        return 'http://collector-collector.opentelemetry-operator-system.svc.cluster.local:4317';
    }
}