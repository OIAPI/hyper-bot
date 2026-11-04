<?php
namespace Core\Rss;

/**
 * RSS 2.0 / Atom 解析。
 *
 * 不用扩展、不引入第三方库：SimpleXML 在 PHP 里默认可用，
 * 用 local-name() 处理带命名空间的 Atom（<atom:link> 之类）。
 */
final class Reader
{
    private const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * 抓取并解析。
     * @return array{ok:bool, items?:array, title?:string, not_modified?:bool, error?:string, status?:int, headers?:array}
     */
    public static function fetch(string $url, string $etag = '', string $modified = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '没有 curl 扩展，无法抓取订阅源'];
        }
        $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.8'];
        if ($etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        if ($modified !== '') {
            $headers[] = 'If-Modified-Since: ' . $modified;
        }
        $resp = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'HyperBot/3.0 (+rss)',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resp): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $resp[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status === 304) {
            return ['ok' => true, 'items' => [], 'not_modified' => true, 'status' => 304, 'headers' => $resp];
        }
        if (!is_string($body) || $status >= 400 || $status === 0) {
            return ['ok' => false, 'error' => $error !== '' ? $error : ('HTTP ' . $status), 'status' => $status, 'headers' => $resp];
        }
        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }
        $parsed = self::parse($body);
        $parsed['status'] = $status;
        $parsed['headers'] = $resp;
        return $parsed;
    }

    /** @return array{ok:bool, items?:array, title?:string, error?:string} */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            return ['ok' => false, 'error' => '不是合法的 XML'];
        }

        $items = [];
        $title = '';
        $root = strtolower((string)$doc->getName());

        if ($root === 'feed') {
            // Atom
            $title = self::text($doc, 'title');
            foreach ($doc->xpath('/*[local-name()="feed"]/*[local-name()="entry"]') ?: [] as $entry) {
                $link = '';
                foreach ($entry->xpath('./*[local-name()="link"]') ?: [] as $l) {
                    $rel = (string)$l['rel'];
                    if ($rel === '' || $rel === 'alternate') {
                        $link = (string)$l['href'];
                        if ($link !== '') {
                            break;
                        }
                    }
                }
                $id = self::text($entry, 'id');
                $items[] = [
                    'uid'       => $id !== '' ? $id : $link,
                    'title'     => self::text($entry, 'title'),
                    'link'      => $link,
                    'summary'   => self::summary(self::text($entry, 'summary') ?: self::text($entry, 'content')),
                    'published' => self::time(self::text($entry, 'published') ?: self::text($entry, 'updated')),
                ];
            }
        } else {
            // RSS 2.0 / RDF
            $channel = $doc->channel ?? null;
            if ($channel !== null) {
                $title = self::text($channel, 'title');
            }
            $nodes = $doc->xpath('//*[local-name()="item"]') ?: [];
            foreach ($nodes as $item) {
                $link = self::text($item, 'link');
                $guid = self::text($item, 'guid');
                $items[] = [
                    'uid'       => $guid !== '' ? $guid : $link,
                    'title'     => self::text($item, 'title'),
                    'link'      => $link,
                    'summary'   => self::summary(self::text($item, 'description')),
                    'published' => self::time(self::text($item, 'pubDate') ?: self::text($item, 'date')),
                ];
            }
        }

        $items = array_values(array_filter($items, static fn(array $row): bool => $row['uid'] !== '' || $row['link'] !== ''));
        foreach ($items as &$row) {
            if ($row['uid'] === '') {
                $row['uid'] = $row['link'];
            }
        }
        unset($row);

        return ['ok' => true, 'items' => $items, 'title' => $title];
    }

    private static function text(\SimpleXMLElement $node, string $name): string
    {
        $found = $node->xpath('./*[local-name()="' . $name . '"]');
        if (!$found) {
            return '';
        }
        $value = (string)$found[0];
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function summary(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        return mb_substr($text, 0, 240);
    }

    private static function time(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts === false ? 0 : $ts;
    }
}
