<?php
declare(strict_types=1);
define('__runtime__', sys_get_temp_dir() . '/hyper-tls-test-' . getmypid());
require __DIR__ . '/../vendor/autoload.php';

use Core\Http\Tls;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

@exec('rm -rf ' . escapeshellarg(__runtime__));
@mkdir(__runtime__, 0775, true);

/** 生成一对自签证书 */
function makeCert(string $cn): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'], random_int(1, 999999));
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    $dir = __runtime__ . '/src';
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $cn . '.crt', $certPem);
    file_put_contents($dir . '/' . $cn . '.key', $keyPem);
    return [$dir . '/' . $cn . '.crt', $dir . '/' . $cn . '.key'];
}

[$cert, $key] = makeCert('example.test');
[$cert2, $key2] = makeCert('other.test');

$r = Tls::bind('', '', '');
check('缺参数被拒绝', !$r['ok']);

$r = Tls::bind($cert, $key2, '');
check('证书与私钥不匹配被拒绝', !$r['ok'], $r['msg'] ?? '');

$r = Tls::bind($cert, $key, 'example.test');
check('绑定成功', (bool)$r['ok'], $r['msg'] ?? '');
check('证书复制到 runtime/ssl', is_file(Tls::certFile()) && is_file(Tls::keyFile()));
// 部分文件系统（FUSE / Android）会忽略 chmod，这里只要求「不对其他人可读」
check('私钥不对 world 开放', (fileperms(Tls::keyFile()) & 0007) === 0, sprintf('%o', fileperms(Tls::keyFile()) & 0777));

$bound = Tls::bound();
check('bound() 读到绑定', $bound !== null);
check('bound() 域名', ($bound['domain'] ?? '') === 'example.test', (string)($bound['domain'] ?? ''));
check('bound() 到期时间', (int)($bound['not_after'] ?? 0) > time());
check('bound() 指纹', ($bound['fingerprint'] ?? '') !== '');

$resolved = Tls::resolve();
check('默认读绑定证书', $resolved['ok'] && $resolved['source'] === 'bound' && $resolved['cert'] === Tls::certFile());

$resolved = Tls::resolve($cert2, $key2);
check('命令行优先', $resolved['ok'] && $resolved['source'] === 'cli' && $resolved['cert'] === $cert2);
check('命令行证书域名', $resolved['domain'] === 'other.test', $resolved['domain']);

$resolved = Tls::resolve($cert2, null);
check('只给证书报错', !$resolved['ok'] && $resolved['error'] !== '');

check('remove 清理', Tls::remove() === true);
check('remove 后 bound()=null', Tls::bound() === null);

@exec('rm -rf ' . escapeshellarg(__runtime__));

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
