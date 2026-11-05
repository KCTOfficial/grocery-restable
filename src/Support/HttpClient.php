<?php

declare(strict_types=1);

namespace GroceryRestable\Support;

use GroceryRestable\Exception\ApiException;

/**
 * Tiny HTTPS client for outgoing calls (Google sign-in), curl when available, PHP streams otherwise.
 *
 * Any callable with the same signature can replace it (tests, proxies):
 *   fn (string $method, string $url, array $form = []): array{status: int, headers: array<string, string>, body: string}
 */
final class HttpClient
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    /**
     * @param array<string, string> $form Sent as application/x-www-form-urlencoded (POST).
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function __invoke(string $method, string $url, array $form = []): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Only https URLs are allowed.');
        }

        return function_exists('curl_init') ? $this->curl($method, $url, $form) : $this->stream($method, $url, $form);
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $method, string $url, array $form): array
    {
        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($form !== []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            throw new ApiException('The identity provider could not be reached.', 502, 'provider_unavailable');
        }

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function stream(string $method, string $url, array $form): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Accept: application/json\r\n" . ($form !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''),
                'content' => $form !== [] ? http_build_query($form) : '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ApiException('The identity provider could not be reached.', 502, 'provider_unavailable');
        }
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
