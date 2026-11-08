<?php

declare(strict_types=1);

namespace Demo\Relay;

use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Exception\HttpException;
use Slimfony\Core\Observability\MetricsController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets Prometheus read the metrics of a command line process, which has no web server of its own.
 *
 * The process calls `serve()` between its units of work; it answers at most one waiting request and never waits for
 * a new one. Only `GET /metrics` exists, and the core's MetricsController decides who may read (bearer token). A client
 * that sends its request slowly can hold the process for at most a second, so this is meant for the private network
 * of the cluster, where only Prometheus connects.
 */
final class MetricsEndpoint
{
    private const int MAX_HEAD_BYTES = 8192;

    /** @var resource */
    private $server;

    public function __construct(
        string $address,
        private readonly MetricsController $controller,
    ) {
        $server = @stream_socket_server($address, $code, $message);
        if (false === $server) {
            throw new ConfigurationException(\sprintf('The metrics endpoint cannot listen on %s: %s', $address, $message));
        }
        stream_set_blocking($server, false);
        $this->server = $server;
    }

    /** The address it listens on (useful when the port was chosen by the system). */
    public function address(): string
    {
        return (string) stream_socket_get_name($this->server, false);
    }

    public function serve(): void
    {
        $connection = @stream_socket_accept($this->server, 0);
        if (false === $connection) {
            return;
        }

        try {
            stream_set_timeout($connection, 1);
            $response = $this->respond($this->readHead($connection));
            $body = (string) $response->getContent();
            fwrite($connection, \sprintf(
                "HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
                $response->getStatusCode(),
                Response::$statusTexts[$response->getStatusCode()] ?? 'Unknown',
                $response->headers->get('Content-Type', 'text/plain'),
                \strlen($body),
                $body,
            ));
        } finally {
            fclose($connection);
        }
    }

    /**
     * @param resource $connection
     */
    private function readHead($connection): string
    {
        $head = '';
        while (!str_contains($head, "\r\n\r\n") && \strlen($head) < self::MAX_HEAD_BYTES) {
            $chunk = fread($connection, 2048);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $head .= $chunk;
        }

        return $head;
    }

    private function respond(string $head): Response
    {
        $lines = explode("\r\n", $head);
        if (1 !== preg_match('#^GET /metrics HTTP/1\.[01]$#', $lines[0])) {
            return new Response('', 1 === preg_match('#^[A-Z]+ \S+ HTTP/1\.[01]$#', $lines[0]) ? 404 : 400);
        }

        $server = [];
        foreach (\array_slice($lines, 1) as $line) {
            if (1 === preg_match('/^authorization:\s*(.+)$/i', $line, $match)) {
                $server['HTTP_AUTHORIZATION'] = trim($match[1]);
            }
        }

        try {
            return ($this->controller)(Request::create('/metrics', server: $server));
        } catch (HttpException $e) { // the controller refuses by exception; the kernel would turn it into a response, here we do
            return new Response('', $e->status, $e->headers);
        }
    }
}
