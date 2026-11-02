<?php
/**
 * QQ 官方机器人 Webhook 签名自检
 * 依赖仅 sodium 扩展，可直接用 php 运行：php tests/qqbot_sign.php
 */
declare(strict_types=1);

$fail = 0;
$ok = function (string $name, bool $cond) use (&$fail) {
	echo ($cond ? "  ✓ " : "  ✗ ") . $name . PHP_EOL;
	if (!$cond) $fail++;
};

echo "== QQBot Ed25519 签名向量 ==
";

// 文档向量：secret=DG5g3B4j9X2KOErG, event_ts=1725442341, plain_token=Arq0D5A61EgUu4OxUvOp
$secret = 'DG5g3B4j9X2KOErG';
$expected = '87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706';

$seed = $secret;
while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) $seed .= $seed;
$seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
$pair = sodium_crypto_sign_seed_keypair($seed);
$signature = bin2hex(sodium_crypto_sign_detached('1725442341' . 'Arq0D5A61EgUu4OxUvOp', sodium_crypto_sign_secretkey($pair)));

$ok('握手签名与官方示例一致', $signature === $expected);
// 公钥派生自检：同一 secret 必须每次派生出相同公钥（幂等性）
$pair2 = sodium_crypto_sign_seed_keypair($seed);
$ok('公钥派生幂等（同 secret 同公钥）', bin2hex(sodium_crypto_sign_publickey($pair2)) === bin2hex(sodium_crypto_sign_publickey($pair)));

// 验签自检：用同一密钥对自签自验
$ts = (string)time();
$body = '{"op":0,"d":{},"t":"GROUP_AT_MESSAGE_CREATE"}';
$sig = sodium_crypto_sign_detached($ts . $body, sodium_crypto_sign_secretkey($pair));
$ok('验签：正确签名可通过', sodium_crypto_sign_verify_detached($sig, $ts . $body, sodium_crypto_sign_publickey($pair)));
$ok('验签：篡改 body 必须失败', !sodium_crypto_sign_verify_detached($sig, $ts . $body . ' ', sodium_crypto_sign_publickey($pair)));
$ok('验签：篡改时间戳必须失败', !sodium_crypto_sign_verify_detached($sig, '1' . $ts . $body, sodium_crypto_sign_publickey($pair)));

echo $fail === 0 ? "
全部通过
" : "
失败 {$fail} 项
";
exit($fail === 0 ? 0 : 1);