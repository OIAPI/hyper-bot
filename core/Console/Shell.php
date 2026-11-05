<?php
namespace Core\Console;

use Core\Version;

/**
 * serve 启动后的交互式控制台。
 *
 * - 行编辑：←/→ 移动光标、Home/End、Backspace/Delete、Ctrl+A/E/U/K/L/C
 * - ↑/↓ 翻历史（记录所有输入过的命令，去重连续重复，最多 100 条）
 * - 原生命令：help / clear / stop / reload / version
 * - 其余命令透传给 php bin/hyper <命令>（独立进程执行），
 *   所以 status / doctor / config / ssl:* / passwd / ext / redis 等都能直接用。
 */
final class Shell
{
    private const PROMPT = 'hyper> ';
    private const MAX_HISTORY = 100;
    /** 这些命令会长期占用进程或覆盖运行中的文件，不允许在会话里执行 */
    private const BLOCKED = ['serve', 'worker', 'install', 'setup', 'update:apply'];

    /** @var list<string> 最新在前 */
    private array $history = [];
    /** -1 = 当前输入；0 = 最近一条历史 */
    private int $historyIndex = -1;
    private string $pendingInput = '';
    private string $buffer = '';
    private int $cursor = 0;
    private string $escape = '';
    private string $utf8Pending = '';

    public function __construct(
        private readonly string $root,
        private readonly string $phpBinary,
        private readonly \Closure $onStop,
        private readonly \Closure $onReload,
    ) {}

    public function banner(): void
    {
        $this->write(PHP_EOL . '  控制台已就绪：输入 help 查看命令；↑/↓ 翻历史，←/→ 移动光标' . PHP_EOL);
        $this->render();
    }

    /** 喂入终端读到的原始字节（可能一次包含多个键） */
    public function feed(string $chunk): void
    {
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $byte = $chunk[$i];
            if ($this->escape !== '') {
                $this->escape .= $byte;
                if ($this->escapeComplete($this->escape)) {
                    $this->handleEscape($this->escape);
                    $this->escape = '';
                }
                continue;
            }
            if ($byte === "\x1b") {
                $this->escape = "\x1b";
                continue;
            }
            $ascii = ord($byte);
            if ($ascii === 13 || $ascii === 10) {
                $this->submit();
                continue;
            }
            if ($ascii === 127 || $ascii === 8) {
                $this->backspace();
                continue;
            }
            if ($ascii < 32) {
                $this->handleControl($ascii);
                continue;
            }
            $this->appendPrintable($byte);
        }
    }

    public function render(): void
    {
        $tail = mb_substr($this->buffer, $this->cursor, null, 'UTF-8');
        $width = $tail === '' ? 0 : mb_strwidth($tail, 'UTF-8');
        $this->write("\r\033[2K" . self::PROMPT . $this->buffer);
        if ($width > 0) {
            $this->write(chr(27) . '[' . $width . 'D');
        }
    }

    // ---------------- 输入编辑 ----------------

    private function escapeComplete(string $seq): bool
    {
        if (str_starts_with($seq, "\x1b[") && strlen($seq) >= 3) {
            return (bool)preg_match('/[\x40-\x7e]$/', $seq);
        }
        if (str_starts_with($seq, "\x1bO")) {
            return strlen($seq) >= 3;
        }
        return strlen($seq) >= 2 && !str_starts_with($seq, "\x1b[") && !str_starts_with($seq, "\x1bO");
    }

    private function handleEscape(string $seq): void
    {
        switch ($seq) {
            case "\x1b[A":
                $this->historyUp();
                break;
            case "\x1b[B":
                $this->historyDown();
                break;
            case "\x1b[C":
                if ($this->cursor < mb_strlen($this->buffer, 'UTF-8')) {
                    $this->cursor++;
                }
                break;
            case "\x1b[D":
                if ($this->cursor > 0) {
                    $this->cursor--;
                }
                break;
            case "\x1b[H":
            case "\x1bOH":
            case "\x1b[1~":
                $this->cursor = 0;
                break;
            case "\x1b[F":
            case "\x1bOF":
            case "\x1b[4~":
                $this->cursor = mb_strlen($this->buffer, 'UTF-8');
                break;
            case "\x1b[3~":
                $this->deleteAtCursor();
                return;
        }
        $this->render();
    }

    private function appendPrintable(string $byte): void
    {
        $this->utf8Pending .= $byte;
        if (!mb_check_encoding($this->utf8Pending, 'UTF-8')) {
            if (strlen($this->utf8Pending) >= 4) {
                $this->utf8Pending = '';
            }
            return;
        }
        $text = $this->utf8Pending;
        $this->utf8Pending = '';
        $before = mb_substr($this->buffer, 0, $this->cursor, 'UTF-8');
        $after = mb_substr($this->buffer, $this->cursor, null, 'UTF-8');
        $this->buffer = $before . $text . $after;
        $this->cursor += mb_strlen($text, 'UTF-8');
        $this->render();
    }

    private function backspace(): void
    {
        if ($this->cursor <= 0) return;
        $before = mb_substr($this->buffer, 0, $this->cursor - 1, 'UTF-8');
        $after = mb_substr($this->buffer, $this->cursor, null, 'UTF-8');
        $this->buffer = $before . $after;
        $this->cursor--;
        $this->render();
    }

    private function deleteAtCursor(): void
    {
        $len = mb_strlen($this->buffer, 'UTF-8');
        if ($this->cursor >= $len) return;
        $before = mb_substr($this->buffer, 0, $this->cursor, 'UTF-8');
        $after = mb_substr($this->buffer, $this->cursor + 1, null, 'UTF-8');
        $this->buffer = $before . $after;
        $this->render();
    }

    private function handleControl(int $ascii): void
    {
        switch ($ascii) {
            case 1: // Ctrl+A
                $this->cursor = 0;
                break;
            case 3: // Ctrl+C
                $this->write(PHP_EOL);
                $this->resetLine();
                break;
            case 4: // Ctrl+D
                $this->write(PHP_EOL . '  用 stop / exit 停止服务' . PHP_EOL);
                break;
            case 5: // Ctrl+E
                $this->cursor = mb_strlen($this->buffer, 'UTF-8');
                break;
            case 11: // Ctrl+K
                $this->buffer = mb_substr($this->buffer, 0, $this->cursor, 'UTF-8');
                break;
            case 12: // Ctrl+L
                $this->write("\033[2J\033[H");
                break;
            case 21: // Ctrl+U
                $this->buffer = mb_substr($this->buffer, $this->cursor, null, 'UTF-8');
                $this->cursor = 0;
                break;
            default:
                return;
        }
        $this->render();
    }

    private function historyUp(): void
    {
        if ($this->history === []) return;
        if ($this->historyIndex === -1) {
            $this->pendingInput = $this->buffer;
            $this->historyIndex = 0;
        } elseif ($this->historyIndex < count($this->history) - 1) {
            $this->historyIndex++;
        }
        $this->buffer = $this->history[$this->historyIndex] ?? '';
        $this->cursor = mb_strlen($this->buffer, 'UTF-8');
    }

    private function historyDown(): void
    {
        if ($this->historyIndex === -1) return;
        if ($this->historyIndex > 0) {
            $this->historyIndex--;
            $this->buffer = $this->history[$this->historyIndex] ?? '';
        } else {
            $this->historyIndex = -1;
            $this->buffer = $this->pendingInput;
            $this->pendingInput = '';
        }
        $this->cursor = mb_strlen($this->buffer, 'UTF-8');
    }

    private function resetLine(): void
    {
        $this->buffer = '';
        $this->cursor = 0;
        $this->historyIndex = -1;
        $this->pendingInput = '';
        $this->utf8Pending = '';
        $this->render();
    }

    // ---------------- 命令 ----------------

    private function submit(): void
    {
        $line = trim($this->buffer);
        $this->write(PHP_EOL);
        $this->buffer = '';
        $this->cursor = 0;
        $this->historyIndex = -1;
        $this->pendingInput = '';
        $this->utf8Pending = '';
        $this->write("\r\033[2K");
        if ($line !== '') {
            $this->remember($line);
            $this->execute($line);
        }
        $this->render();
    }

    private function remember(string $line): void
    {
        if ($this->history !== [] && $this->history[0] === $line) {
            return;
        }
        array_unshift($this->history, $line);
        if (count($this->history) > self::MAX_HISTORY) {
            array_pop($this->history);
        }
    }

    private function execute(string $line): void
    {
        $tokens = str_getcsv($line, ' ');
        $tokens = array_values(array_filter($tokens, static fn($v) => $v !== '' && $v !== null));
        if ($tokens === []) return;
        $command = strtolower((string)array_shift($tokens));

        switch ($command) {
            case 'help':
            case '?':
                $this->printHelp();
                return;
            case 'clear':
                $this->write("\033[2J\033[H");
                return;
            case 'stop':
            case 'exit':
            case 'quit':
                ($this->onStop)();
                return;
            case 'reload':
            case 'restart':
                ($this->onReload)();
                return;
            case 'version':
                $this->write('  Hyper Bot ' . Version::current() . PHP_EOL);
                return;
        }

        if (in_array($command, self::BLOCKED, true)) {
            $this->write('  ' . $command . ' 不能在运行中的控制台里执行；请在服务器终端里单独运行。' . PHP_EOL);
            return;
        }

        $this->runExternal($command, $tokens);
    }

    /** 透传给 bin/hyper，独立进程执行，输出直接打印到终端 */
    private function runExternal(string $command, array $args): void
    {
        $binary = $this->phpBinary !== '' ? $this->phpBinary : 'php';
        $script = $this->root . '/bin/hyper';
        if (!is_file($script)) {
            $this->write('  找不到 bin/hyper：' . $script . PHP_EOL);
            return;
        }
        $cmd = array_merge([$binary, $script, $command], $args);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDOUT,
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $this->root);
        if (!is_resource($proc)) {
            $this->write('  无法启动命令进程：' . implode(' ', $cmd) . PHP_EOL);
            return;
        }
        $code = proc_close($proc);
        if ($code !== 0) {
            $this->write('  （退出码 ' . $code . '）' . PHP_EOL);
        }
    }

    private function printHelp(): void
    {
        $this->write(PHP_EOL);
        $this->write('  控制台命令' . PHP_EOL);
        $this->write('    help / ?            这个帮助' . PHP_EOL);
        $this->write('    clear               清屏（Ctrl+L 同效）' . PHP_EOL);
        $this->write('    stop / exit / quit  停止服务' . PHP_EOL);
        $this->write('    reload / restart    重载 Worker 进程' . PHP_EOL);
        $this->write('    version             版本号' . PHP_EOL);
        $this->write(PHP_EOL);
        $this->write('  其余命令会转给 bin/hyper 执行，例如：' . PHP_EOL);
        $this->write('    status | doctor | redis | ext github.ping' . PHP_EOL);
        $this->write('    config runtime.arch php' . PHP_EOL);
        $this->write('    ssl:show | ssl:bind --cert=... --key=... | ssl:remove' . PHP_EOL);
        $this->write('    passwd <用户名> <新密码>' . PHP_EOL);
        $this->write('  不支持：serve / worker / install / update:apply' . PHP_EOL);
        $this->write(PHP_EOL);
        $this->write('  编辑：↑/↓ 历史，←/→ 光标，Home/End，Backspace/Delete，Ctrl+C 取消' . PHP_EOL);
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }
}
