<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useThemeStore } from '@/stores/theme'
import { ApiError } from '@/api/client'
import { Hide, Lock, Moon, Sunny, User, View } from '@element-plus/icons-vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const toast = useToastStore()
const theme = useThemeStore()

const username = ref('')
const password = ref('')
const error = ref('')
const submitting = ref(false)
const pwVisible = ref(false)

const isDark = computed(() => theme.effective === 'dark')

onMounted(async () => {
  await auth.checkStatus()
})

function toggleMode(): void {
  theme.setMode(isDark.value ? 'light' : 'dark')
}

async function submit(): Promise<void> {
  error.value = ''
  if (!username.value.trim() || !password.value) {
    error.value = '请输入用户名与密码'
    return
  }
  submitting.value = true
  try {
    await auth.login(username.value.trim(), password.value)
    toast.ok('登录成功')
    const redirect = (route.query.redirect as string) || '/'
    void router.replace(redirect)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : '登录失败'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <button class="mode-switch" type="button" :title="isDark ? '切换亮色' : '切换暗色'" @click="toggleMode">
      <UiIcon><component :is="isDark ? Sunny : Moon" /></UiIcon>
    </button>

    <div class="login-card">
      <div class="login-brand">
        <div class="login-mark">H</div>
        <div class="login-text">
          <h1>Hyper Bot</h1>
          <p>控制台登录</p>
        </div>
      </div>

      <UiAlert v-if="!auth.initialized" type="warn" title="尚未初始化管理员账号" class="init-alert">
        <div class="init-tip">
          首次启动会自动生成初始密码，请查看 <code>runtime/webui/initial-password.txt</code>，或运行
          <code>bin/hyper.sh doctor</code> 查看提示。
        </div>
      </UiAlert>

      <UiForm class="login-form" @submit.prevent="submit">
        <UiInput
          v-model="username"
          size="large"
          placeholder="用户名"
          autocomplete="username"
          autofocus
          :disabled="submitting"
          @enter="submit"
        >
          <template #prefix>
            <UiIcon><User /></UiIcon>
          </template>
        </UiInput>
        <UiInput
          v-model="password"
          :type="pwVisible ? 'text' : 'password'"
          size="large"
          placeholder="密码"
          autocomplete="current-password"
          :disabled="submitting"
          @enter="submit"
        >
          <template #prefix>
            <UiIcon><Lock /></UiIcon>
          </template>
          <template #suffix>
            <button
              class="pw-eye"
              type="button"
              tabindex="-1"
              :aria-label="pwVisible ? '隐藏密码' : '显示密码'"
              @click="pwVisible = !pwVisible"
            >
              <UiIcon><component :is="pwVisible ? Hide : View" /></UiIcon>
            </button>
          </template>
        </UiInput>

        <UiAlert v-if="error" type="danger" :title="error" class="err" />

        <UiButton type="primary" size="large" class="submit" :loading="submitting" @click="submit">
          {{ submitting ? '登录中…' : '登录' }}
        </UiButton>
      </UiForm>

      <div class="login-foot">Hyper Bot · 基于 Swoole 的 QQ / OneBot 机器人框架</div>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100%;
  display: grid;
  place-items: center;
  padding: 24px;
  background: var(--bg);
  position: relative;
}

.mode-switch {
  position: absolute;
  top: 16px;
  right: 16px;
  width: 32px;
  height: 32px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-card);
  color: var(--text-dim);
  display: grid;
  place-items: center;
  cursor: pointer;
  font-size: 13px;
}
.mode-switch:hover { background: var(--bg-hover); color: var(--text); }

.login-card {
  width: 100%;
  max-width: 352px;
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow);
  padding: 30px 28px 22px;
}

.login-brand { display: flex; align-items: center; gap: 11px; margin-bottom: 22px; }
.login-mark {
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  background: var(--accent);
  color: var(--text-inverse);
  display: grid;
  place-items: center;
  font-size: 17px;
  font-weight: 700;
}
.login-text h1 { margin: 0; font-size: 16.5px; font-weight: 660; letter-spacing: -0.01em; }
.login-text p { margin: 2px 0 0; font-size: 11.5px; color: var(--text-faint); }

.init-alert { margin-bottom: 14px; }
.init-tip { font-size: 11.5px; line-height: 1.65; }
.init-tip code {
  background: var(--bg-active);
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 10.5px;
}

.login-form { display: flex; flex-direction: column; gap: 14px; }
.err { margin: 0; }
.submit { width: 100%; margin-top: 4px; }
.pw-eye {
  border: none;
  background: transparent;
  color: var(--text-faint);
  font-family: inherit;
  font-size: 14px;
  line-height: 0;
  display: grid;
  place-items: center;
  cursor: pointer;
  padding: 2px;
  border-radius: 4px;
}
.pw-eye:hover { color: var(--accent); }

.login-foot {
  margin-top: 20px;
  text-align: center;
  font-size: 10.5px;
  color: var(--text-faint);
  line-height: 1.5;
}
</style>
