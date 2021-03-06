<?php
declare(strict_types = 1);

namespace Embed;

use Exception;
use Psr\Http\Message\UriInterface;
use SimpleXMLElement;

class OEmbed
{
    use HttpApiTrait;

    private static $providers;

    private static function getProviders(): array
    {
        if (!is_array(self::$providers)) {
            self::$providers = require __DIR__.'/resources/oembed.php';
        }

        return self::$providers;
    }

    protected function fetchData(): array
    {
        $this->endpoint = $this->detectEndpoint();

        if (empty($this->endpoint)) {
            return [];
        }

        $crawler = $this->extractor->getCrawler();
        $request = $crawler->createRequest('GET', $this->endpoint);
        $response = $crawler->sendRequest($request);

        if (self::isXML($request->getUri())) {
            return self::extractXML((string) $response->getBody());
        }

        return self::extractJSON((string) $response->getBody());
    }

    private function detectEndpoint(): ?UriInterface
    {
        $document = $this->extractor->getDocument();

        return $document->link('alternate', ['type' => 'application/json+oembed'])
            ?: $document->link('alternate', ['type' => 'text/json+oembed'])
            ?: $document->link('alternate', ['type' => 'application/xml+oembed'])
            ?: $document->link('alternate', ['type' => 'text/xml+oembed'])
            ?: $this->detectEndpointFromProviders();
    }

    private function detectEndpointFromProviders(): ?UriInterface
    {
        $url = (string) $this->extractor->getUri();
        $endpoint = self::searchEndpoint(self::getProviders(), $url);

        if (!$endpoint) {
            return null;
        }

        return $this->extractor->getCrawler()
            ->createUri($endpoint)
            ->withQuery(http_build_query(['url' => $url, 'format' => 'json']));
    }

    private static function searchEndpoint(array $providers, string $url): ?string
    {
        foreach ($providers as $endpoint => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $url)) {
                    return $endpoint;
                }
            }
        }

        return null;
    }

    private static function isXML(UriInterface $uri): bool
    {
        $extension = pathinfo($uri->getPath(), PATHINFO_EXTENSION);

        if (strtolower($extension) === 'xml') {
            return true;
        }

        parse_str($uri->getQuery(), $params);
        $format = $params['format'] ?? null;

        if ($format && strtolower($format) === 'xml') {
            return true;
        }

        return false;
    }

    private static function extractXML(string $xml): array
    {
        try {
            $data = [];
            $errors = libxml_use_internal_errors(true);
            $content = new SimpleXMLElement($xml);
            libxml_use_internal_errors($errors);

            foreach ($content as $element) {
                $value = trim((string) $element);

                if (stripos($value, '<![CDATA[') === 0) {
                    $value = substr($value, 9, -3);
                }

                $name = $element->getName();
                $data[$name] = $value;
            }

            return $data;
        } catch (Exception $exception) {
            return [];
        }
    }

    private static function extractJSON(string $json): array
    {
        try {
            return json_decode($json, true) ?: [];
        } catch (Exception $exception) {
            return [];
        }
    }
}
