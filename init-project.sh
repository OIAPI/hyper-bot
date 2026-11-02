#!/bin/bash

# ==============================================
# 功能：项目初始化脚本（检测环境 + 可选 Composer 操作）
# 用法：1. 默认使用系统 PHP：./start.sh
#       2. 指定 PHP 路径：./start.sh /usr/bin/php8.4
# ==============================================

# --------------------------
# 成功提示函数
# --------------------------
show_success() {
    echo -e "\n\033[32m==============================================\033[0m"
    echo -e "\033[32m✓ 所有检测/操作完成！\033[0m"
    echo -e "\033[32m当前 PHP 路径：$PHP_BIN\033[0m"
    echo -e "\033[32m当前 PHP 版本：$($PHP_BIN -v | head -n1 | awk '{print $2}')\033[0m"
    echo -e "\033[32m==============================================\033[0m"
    echo -e "\033[33m下一步可执行：$PHP_BIN ./index.php\033[0m"
    exit 0
}

# --------------------------
# 1. 处理 PHP 路径参数（支持用户指定）
# --------------------------
if [ $# -eq 1 ]; then
    PHP_BIN="$1"
else
    PHP_BIN="php"
fi

# 检测指定 PHP 是否存在
if ! command -v "$PHP_BIN" &> /dev/null; then
    echo -e "\033[31m错误：未找到 PHP 可执行文件！\033[0m"
    echo "指定路径：$PHP_BIN"
    echo "解决方案：1. 确认路径正确；2. 安装 PHP 8.1+（如 sudo apt install php8.4）"
    exit 1
fi

# --------------------------
# 2. 检测 PHP 版本 ≥ 8.1
# --------------------------
PHP_MAJOR=$("$PHP_BIN" -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$("$PHP_BIN" -r "echo PHP_MINOR_VERSION;")

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]); then
    echo -e "\033[31m错误：PHP 版本过低！\033[0m"
    echo "当前版本：$($PHP_BIN -v | head -n1 | awk '{print $2}')"
    echo "要求：PHP 8.1 及以上"
    echo "解决方案：1. 升级 PHP；2. 指定高版本路径（如 ./start.sh /usr/bin/php8.4）"
    exit 1
fi

# --------------------------
# 3. 检测 PHP 是否启用 Swoole 扩展
# --------------------------
if ! "$PHP_BIN" -m | grep -qi "swoole"; then
    echo -e "\033[31m错误：PHP 未启用 Swoole 扩展！\033[0m"
    echo "当前 PHP 路径：$PHP_BIN"
    echo "安装指引："
    echo "  1. 执行 $PHP_BIN -m | grep pecl 确认 pecl 可用"
    echo "  2. 安装 Swoole：sudo $PHP_BIN -d memory_limit=-1 $(command -v pecl) install swoole"
    echo "  3. 启用扩展：在 php.ini 中添加 extension=swoole.so"
    exit 1
fi

# --------------------------
# 4. 检测 Composer + 让用户决定是否安装/执行
# --------------------------
composer_exists=0
if command -v composer &> /dev/null; then
    composer_exists=1
    echo -e "\033[32m✓ 已检测到 Composer（版本：$(composer -V | awk '{print $3}')）\033[0m"
else
    echo -e "\033[33m⚠ 未检测到 Composer\033[0m"
fi

# 询问用户是否执行 Composer 相关操作
read -p $'\n请选择操作：\n1. 执行 composer install + dump-autoload\n2. 仅检查环境（不执行 Composer）\n3. 安装 Composer 后执行操作\n请输入数字（1/2/3）：' user_choice

# 根据用户选择执行对应逻辑
case $user_choice in
    1)
        if [ $composer_exists -eq 0 ]; then
            echo -e "\033[31m错误：未检测到 Composer，无法执行操作！\033[0m"
            echo "请选择 3 安装 Composer，或手动安装后重试"
            exit 1
        fi
        ;;
    2)
        echo -e "\n\033[32m✓ 环境检测通过，未执行 Composer 操作\033[0m"
        show_success
        ;;
    3)
        echo -e "\n\033[33m正在安装最新版 Composer...\033[0m"
        # 用当前 PHP 下载安装 Composer
        "$PHP_BIN" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        "$PHP_BIN" -r "if (hash_file('sha384', 'composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c22') { echo '✓ 安装包验证通过\n'; } else { echo '✗ 安装包损坏'; unlink('composer-setup.php'); exit(1); }"
        "$PHP_BIN" composer-setup.php
        "$PHP_BIN" -r "unlink('composer-setup.php');"
        # 移动到系统路径（全局可用）
        sudo mv composer.phar /usr/local/bin/composer
        echo -e "\033[32m✓ Composer 安装完成（可执行 composer -V 验证）\033[0m"
        ;;
    *)
        echo -e "\033[31m错误：无效选择！请输入 1/2/3\033[0m"
        exit 1
        ;;
esac

# --------------------------
# 5. 执行 Composer 操作（用户选择 1 或 3 时触发）
# --------------------------
echo -e "\n\033[32m开始执行 composer install...\033[0m"
composer install --no-interaction
if [ $? -ne 0 ]; then
    echo -e "\033[31m✗ composer install 失败！\033[0m"
    echo "解决方案：1. 检查网络（需连接 Packagist）；2. 清除缓存（composer clear-cache）"
    exit 1
fi

echo -e "\033[32m开始执行 composer dump-autoload...\033[0m"
composer dump-autoload --optimize
if [ $? -ne 0 ]; then
    echo -e "\033[31m✗ composer dump-autoload 失败！\033[0m"
    echo "解决方案：删除 vendor 目录后重试（rm -rf vendor && composer install）"
    exit 1
fi

# --------------------------
# 6. 成功提示
# --------------------------
show_success