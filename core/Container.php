<?php
namespace Core;

/**
 * 极简依赖注入容器。
 *
 * 不引入第三方 DI（项目要在没有 composer install 的环境里也能跑），
 * 只支持三种能力：单例绑定、工厂绑定、按类名自动装配。
 */
final class Container {
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $bindings = [];
    /** @var array<string, \Closure> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, \Closure> */
    private array $resolved = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $container): void {
        self::$instance = $container;
    }

    /** 绑定一个已有实例 */
    public function bind(string $id, mixed $value): self {
        $this->bindings[$id] = $value;
        unset($this->instances[$id], $this->resolved[$id]);
        return $this;
    }

    /** 绑定一个工厂（首次获取时调用，之后复用结果） */
    public function singleton(string $id, \Closure $factory): self {
        $this->factories[$id] = $factory;
        unset($this->instances[$id], $this->resolved[$id]);
        return $this;
    }

    /** 每次获取都新建 */
    public function factory(string $id, \Closure $factory): self {
        $this->resolved[$id] = $factory;
        return $this;
    }

    public function has(string $id): bool {
        return isset($this->bindings[$id]) || isset($this->factories[$id]) || isset($this->instances[$id]) || isset($this->resolved[$id]);
    }

    public function get(string $id): mixed {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (array_key_exists($id, $this->bindings)) {
            return $this->bindings[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        if (isset($this->resolved[$id])) {
            return ($this->resolved[$id])($this);
        }
        if (class_exists($id)) {
            // 无参构造的类自动装配
            return $this->instances[$id] = new $id();
        }
        throw new \RuntimeException("container: cannot resolve {$id}");
    }

    /** 覆盖已解析的实例（测试用） */
    public function set(string $id, mixed $value): void {
        $this->instances[$id] = $value;
    }

    /** 移除单个绑定（含已解析的实例/工厂） */
    public function forget(string $id): void {
        unset($this->bindings[$id], $this->factories[$id], $this->instances[$id], $this->resolved[$id]);
    }

    /** 移除某个前缀下的全部绑定（用于「清空 API 实例缓存」这类批量操作） */
    public function forgetPrefix(string $prefix): int {
        $count = 0;
        foreach (['bindings', 'factories', 'instances', 'resolved'] as $bucket) {
            foreach (array_keys($this->{$bucket}) as $id) {
                if (str_starts_with((string)$id, $prefix)) {
                    unset($this->{$bucket}[$id]);
                    $count++;
                }
            }
        }
        return $count;
    }

    public function reset(): void {
        $this->bindings = [];
        $this->factories = [];
        $this->instances = [];
        $this->resolved = [];
    }
}
