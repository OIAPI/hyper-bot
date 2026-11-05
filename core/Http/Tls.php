<?php
namespace Core\Http;

/**
 * 面板 HTTPS 证书管理：绑定 / 读取 / 本次启动覆盖。
 *
 * 优先级：serve --ssl-cert/--ssl-key（只影响本次启动）
 *        > runtime/ssl 下绑定的证书（每次启动自动读取）
 *
 * 绑定时会把证书和私钥复制到 runtime/ssl，之后原文件挪走也不影响启动；
 * 私钥权限收紧到 0600。
 */
final class Tls
{
    public static function dir(): string
    {
        return __runtime__ . '/ssl';
    }

    public static function certFile(): string
    {
        return self::dir() . '/cert.pem';
    }

    public static function keyFile(): string
    {
        return self::dir() . '/key.pem';
    }

    public static function metaFile(): string
    {
        return self::dir() . '/bound.json';
    }

    /**
     * 绑定证书：校验证书/私钥、是否匹配、是否过期，然后复制到 runtime/ssl。
     *
     * @return array{ok:bool, msg:string, data?:array<string,mixed>}
     */
    public static function bind(string $cert, string $key, string $domain = ''): array
    {
        $cert = trim($cert);
        $key = trim($key);
        if ($cert === '' || $key === '') {
            return ['ok' => false, 'msg' => '需要同时提供 --cert=证书文件 和 --key=私钥文件'];
        }
        if (!is_file($cert) || !is_readable($cert)) {
            return ['ok' => false, 'msg' => '证书文件不可读：' . $cert];
        }
        if (!is_file($key) || !is_readable($key)) {
            return ['ok' => false, 'msg' => '私钥文件不可读：' . $key];
        }
        $info = self::inspect($cert, $key);
        if (!$info['ok']) {
            return $info;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'msg' => '创建目录失败：' . $dir];
        }
        $tmpCert = $dir . '/cert.pem.tmp';
        $tmpKey = $dir . '/key.pem.tmp';
        if (!@copy($cert, $tmpCert) || !@copy($key, $tmpKey)) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            return ['ok' => false, 'msg' => '复制证书/私钥失败（检查目录权限）'];
        }
        @chmod($tmpKey, 0600);
        if (!@rename($tmpCert, self::certFile()) || !@rename($tmpKey, self::keyFile())) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            return ['ok' => false, 'msg' => '写入 runtime/ssl 失败'];
        }
        $meta = array_merge($info['data'] ?? [], [
            'domain'      => $domain !== '' ? $domain : (string)($info['data']['domain'] ?? ''),
            'bound_at'    => time(),
            'source_cert' => $cert,
            'source_key'  => $key,
        ]);
        @file_put_contents(
            self::metaFile(),
            (string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        return ['ok' => true, 'msg' => '证书已绑定', 'data' => $meta];
    }

    /**
     * 校验证书 / 私钥（PEM，未加密），返回摘要信息。
     *
     * @return array{ok:bool, msg:string, data?:array<string,mixed>}
     */
    public static function inspect(string $certFile, string $keyFile): array
    {
        $certPem = @file_get_contents($certFile);
        $keyPem = @file_get_contents($keyFile);
        if ($certPem === false || trim($certPem) === '') {
            return ['ok' => false, 'msg' => '证书文件为空或无法读取'];
        }
        if ($keyPem === false || trim($keyPem) === '') {
            return ['ok' => false, 'msg' => '私钥文件为空或无法读取'];
        }
        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            return ['ok' => false, 'msg' => '证书解析失败：需要 PEM 格式（fullchain / cert.pem）'];
        }
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            return ['ok' => false, 'msg' => '私钥解析失败：需要未加密的 PEM 私钥'];
        }
        if (!@openssl_x509_check_private_key($cert, $key)) {
            return ['ok' => false, 'msg' => '证书与私钥不匹配'];
        }
        $parsed = @openssl_x509_parse($cert);
        $parsed = is_array($parsed) ? $parsed : [];
        $notAfter = (int)($parsed['validTo_time_t'] ?? 0);
        $notBefore = (int)($parsed['validFrom_time_t'] ?? 0);
        return ['ok' => true, 'msg' => 'ok', 'data' => [
            'subject'     => (string)($parsed['name'] ?? ''),
            'domain'      => (string)($parsed['subject']['CN'] ?? ''),
            'not_before'  => $notBefore,
            'not_after'   => $notAfter,
            'fingerprint' => (string)@openssl_x509_fingerprint($cert, 'sha256'),
            'expired'     => $notAfter > 0 && $notAfter < time(),
        ]];
    }

    /** 读取已绑定的证书；文件缺失或损坏返回 null */
    public static function bound(): ?array
    {
        $cert = self::certFile();
        $key = self::keyFile();
        if (!is_file($cert) || !is_file($key)) {
            return null;
        }
        $info = self::inspect($cert, $key);
        if (!$info['ok']) {
            return null;
        }
        $meta = [];
        if (is_file(self::metaFile())) {
            $decoded = json_decode((string)@file_get_contents(self::metaFile()), true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        return array_merge($meta, ['cert' => $cert, 'key' => $key], $info['data'] ?? []);
    }

    /**
     * 解析本次启动使用的证书：命令行优先，其次已绑定。
     *
     * @return array{ok:bool, source:string, cert:?string, key:?string, domain:string, not_after:int, error:string}
     */
    public static function resolve(?string $cert = null, ?string $key = null): array
    {
        $cliCert = trim((string)$cert);
        $cliKey = trim((string)$key);
        $empty = ['ok' => false, 'source' => '', 'cert' => null, 'key' => null, 'domain' => '', 'not_after' => 0, 'error' => ''];
        if ($cliCert !== '' || $cliKey !== '') {
            if ($cliCert === '' || $cliKey === '') {
                return array_merge($empty, ['source' => 'cli', 'error' => '--ssl-cert 与 --ssl-key 必须同时提供']);
            }
            $info = self::inspect($cliCert, $cliKey);
            if (!$info['ok']) {
                return array_merge($empty, ['source' => 'cli', 'error' => (string)$info['msg']]);
            }
            return [
                'ok' => true, 'source' => 'cli',
                'cert' => $cliCert, 'key' => $cliKey,
                'domain' => (string)($info['data']['domain'] ?? ''),
                'not_after' => (int)($info['data']['not_after'] ?? 0),
                'error' => '',
            ];
        }
        $bound = self::bound();
        if ($bound === null) {
            return array_merge($empty, ['error' => '未绑定证书']);
        }
        return [
            'ok' => true, 'source' => 'bound',
            'cert' => (string)$bound['cert'], 'key' => (string)$bound['key'],
            'domain' => (string)($bound['domain'] ?? ''),
            'not_after' => (int)($bound['not_after'] ?? 0),
            'error' => '',
        ];
    }

    public static function remove(): bool
    {
        $ok = true;
        foreach ([self::certFile(), self::keyFile(), self::metaFile()] as $file) {
            if (is_file($file) && !@unlink($file)) {
                $ok = false;
            }
        }
        return $ok;
    }
}
