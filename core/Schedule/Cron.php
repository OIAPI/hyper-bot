<?php
namespace Core\Schedule;

/**
 * 5 段 cron 表达式（分 时 日 月 周）。
 *
 * 支持 星号、星号斜杠n（间隔）、a-b、a-b/n、a,b,c，以及 @hourly / @daily / @weekly / @monthly 别名。
 * 周字段 0 和 7 都表示周日（与 Linux crontab 一致）。
 *
 * 为什么自己写而不引 composer 包：格式就这么点，自己实现能给出中文报错，
 * 也让「下次执行时间」在面板里可预览 —— 这是定时任务最容易配错的地方。
 */
final class Cron
{
    public const ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    /** @var array<int, array<int, true>> 各字段允许的取值 */
    private array $fields = [];
    private string $expression;

    private function __construct(string $expression)
    {
        $this->expression = $expression;
    }

    /** 解析并校验；失败返回 [null, 错误信息] */
    public static function parse(string $expression): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $expression) ?? '');
        if ($raw === '') {
            return [null, '表达式不能为空，例如 0 8 * * *'];
        }
        $lower = strtolower($raw);
        if (isset(self::ALIASES[$lower])) {
            $raw = self::ALIASES[$lower];
        }
        $parts = explode(' ', $raw);
        if (count($parts) !== 5) {
            return [null, '需要 5 段（分 时 日 月 周），当前是 ' . count($parts) . ' 段；例如 0 8 * * *'];
        }

        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
        $names = ['分', '时', '日', '月', '周'];
        $cron = new self($raw);
        foreach ($parts as $i => $part) {
            [$min, $max] = $ranges[$i];
            $set = self::parseField($part, $min, $max);
            if (is_string($set)) {
                return [null, "第 " . ($i + 1) . " 段（{$names[$i]}）" . $set];
            }
            $cron->fields[$i] = $set;
        }
        // 周日兼容 7 → 0
        if (isset($cron->fields[4][7])) {
            $cron->fields[4][0] = true;
            unset($cron->fields[4][7]);
        }
        return [$cron, null];
    }

    /** @return array<int,true>|string 成功返回取值集合，失败返回原因 */
    private static function parseField(string $part, int $min, int $max)
    {
        $set = [];
        foreach (explode(',', $part) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                return '有空项（多余的逗号）';
            }
            $step = 1;
            if (str_contains($piece, '/')) {
                [$base, $stepRaw] = explode('/', $piece, 2);
                if (!ctype_digit($stepRaw) || (int)$stepRaw <= 0) {
                    return "步长必须是正整数：{$piece}";
                }
                $step = (int)$stepRaw;
                $piece = $base === '' ? '*' : $base;
            }
            if ($piece === '*') {
                $from = $min;
                $to = $max;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $piece, $m)) {
                $from = (int)$m[1];
                $to = (int)$m[2];
                if ($from > $to) {
                    return "区间起点大于终点：{$piece}";
                }
            } elseif (preg_match('/^\d+$/', $piece)) {
                $from = $to = (int)$piece;
            } else {
                return "无法识别：{$piece}";
            }
            if ($from < $min || $to > $max) {
                return "超出范围 {$min}-{$max}：{$piece}";
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $set[$v] = true;
            }
        }
        return $set === [] ? '没有任何有效取值' : $set;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /** 某一分钟是否命中（$timestamp 取该分钟的任意秒） */
    public function matches(int $timestamp): bool
    {
        $t = getdate($timestamp);
        $dow = (int)$t['wday'];
        if (!isset($this->fields[0][(int)$t['minutes']])) {
            return false;
        }
        if (!isset($this->fields[1][(int)$t['hours']])) {
            return false;
        }
        if (!isset($this->fields[3][(int)$t['mon']])) {
            return false;
        }
        $domOk = isset($this->fields[2][(int)$t['mday']]);
        $dowOk = isset($this->fields[4][$dow]);
        // crontab 语义：日与周都受限时，满足其一即可
        $domRestricted = count($this->fields[2]) !== 31;
        $dowRestricted = count($this->fields[4]) !== 7;
        if ($domRestricted && $dowRestricted) {
            $dayOk = $domOk || $dowOk;
        } else {
            $dayOk = $domOk && $dowOk;
        }
        return $dayOk;
    }

    /**
     * 下一次执行时间（严格晚于 $after）。
     * @return int|null 366 天内没有匹配则返回 null
     */
    public function nextRun(int $after): ?int
    {
        $ts = (int)(floor($after / 60) * 60) + 60;
        $limit = $ts + 366 * 86400;
        while ($ts <= $limit) {
            if ($this->matches($ts)) {
                return $ts;
            }
            $ts += 60;
        }
        return null;
    }

    /** 预览接下来的 N 次（面板用） */
    public function upcoming(int $after, int $count = 3): array
    {
        $out = [];
        $ts = $after;
        for ($i = 0; $i < $count; $i++) {
            $next = $this->nextRun($ts);
            if ($next === null) {
                break;
            }
            $out[] = $next;
            $ts = $next;
        }
        return $out;
    }

    /** 常用表达式（面板的快捷选项） */
    public static function presets(): array
    {
        return [
            ['expression' => '@hourly', 'label' => '每小时整点'],
            ['expression' => '0 8 * * *', 'label' => '每天 08:00'],
            ['expression' => '0 20 * * *', 'label' => '每天 20:00'],
            ['expression' => '0 9 * * 1', 'label' => '每周一 09:00'],
            ['expression' => '0 0 1 * *', 'label' => '每月 1 号 0 点'],
            ['expression' => '*/30 * * * *', 'label' => '每 30 分钟'],
        ];
    }
}