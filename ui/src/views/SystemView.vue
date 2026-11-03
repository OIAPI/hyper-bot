<script setup lang="ts">
/**
 * 系统：磁盘 / 健康检查 / 缓存 / 路由清单。全部控件走 UiXxx 抽象层。
 */
import { ref, onMounted } from 'vue'
import { systemApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { formatBytes } from '@/utils/format'
import type { UiColumn } from '@/ui/types'
import type { DiskData, HealthData, RouteItem } from '@/types/api'

const toast = useToastStore()
const disk = ref<DiskData | null>(null)
const health = ref<HealthData | null>(null)
const routes = ref<RouteItem[]>([])
const loading = ref(true)
const clearing = ref(false)

const healthColumns: UiColumn[] = [
  { key: 'name', title: '检查项', minWidth: 110 },
  { key: 'detail', title: '详情', minWidth: 150 },
  { key: 'ok', title: '状态', width: 84, align: 'right' },
]

const routeColumns: UiColumn[] = [
  { key: 'method', title: '方法', width: 90 },
  { key: 'path', title: '路径', minWidth: 240 },
  { key: 'name', title: '名称', minWidth: 160 },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    const [d, h, r] = await Promise.all([systemApi.disk(), systemApi.health(), systemApi.routes()])
    disk.value = d
    health.value = h
    routes.value = r
  } catch (e) {
    toast.fromError(e, '加载系统信息失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function clearCache(): Promise<void> {
  clearing.value = true
  try {
    await systemApi.clearCache()
    toast.ok('缓存已清除')
  } catch (e) {
    toast.fromError(e, '清除失败')
  } finally {
    clearing.value = false
  }
}

function usagePercent(d: DiskData): number {
  if (!d.total) return 0
  return Math.round((d.used / d.total) * 100)
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>系统</h1>
        <p class="sub">磁盘占用、健康检查、缓存与路由清单。</p>
      </div>
      <UiButton size="small" @click="load">刷新</UiButton>
    </header>

    <UiCard v-if="loading">
      <UiSkeleton :rows="6" animated />
    </UiCard>

    <template v-else>
      <div class="grid-2">
        <UiCard>
          <template #header><span class="card-title">磁盘</span></template>
          <div v-if="disk" class="disk">
            <div class="disk-line">
              <span class="tiny faint">已用</span>
              <span class="mono small">{{ formatBytes(disk.used) }} / {{ formatBytes(disk.total) }}</span>
            </div>
            <UiProgress
              :percentage="usagePercent(disk)"
              :stroke-width="8"
              :status="usagePercent(disk) > 90 ? 'danger' : undefined"
            />
            <div class="tiny faint">剩余 {{ formatBytes(disk.free) }}（{{ usagePercent(disk) }}% 已用）</div>
          </div>
          <UiEmpty v-else description="暂无磁盘数据" />
        </UiCard>

        <UiCard padding="none">
          <template #header>
            <div class="card-head-row">
              <span class="card-title">健康检查</span>
              <div class="spacer" />
              <UiTag v-if="health" :type="health.ok ? 'ok' : 'danger'" size="small">
                {{ health.ok ? '正常' : '有异常' }}
              </UiTag>
            </div>
          </template>
          <UiTable v-if="health?.checks?.length" :data="health.checks" :columns="healthColumns" row-key="name">
            <template #cell-detail="{ row }">
              <span class="tiny faint">{{ row.detail }}</span>
            </template>
            <template #cell-ok="{ row }">
              <UiTag :type="row.ok ? 'ok' : 'danger'" size="small">{{ row.ok ? 'OK' : 'FAIL' }}</UiTag>
            </template>
          </UiTable>
          <UiEmpty v-else description="暂无健康检查数据" />
        </UiCard>
      </div>

      <UiCard>
        <div class="cache-row">
          <div class="grow">
            <div class="small strong">缓存</div>
            <div class="tiny faint">清除运行时缓存（不影响配置与数据）</div>
          </div>
          <UiButton size="small" :loading="clearing" @click="clearCache">
            {{ clearing ? '清除中…' : '清除缓存' }}
          </UiButton>
        </div>
      </UiCard>

      <UiCard padding="none">
        <template #header>
          <div class="card-head-row">
            <span class="card-title">路由清单</span>
            <div class="spacer" />
            <UiTag type="info" effect="plain" size="small">{{ routes.length }} 条</UiTag>
          </div>
        </template>
        <UiTable :data="routes" :columns="routeColumns" max-height="420">
          <template #cell-method="{ row }">
            <UiTag type="info" effect="plain" size="small">{{ row.method }}</UiTag>
          </template>
          <template #cell-path="{ row }">
            <span class="mono tiny">{{ row.path }}</span>
          </template>
          <template #cell-name="{ row }">
            <span class="tiny faint">{{ row.name }}</span>
          </template>
        </UiTable>
      </UiCard>
    </template>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.page-head { margin-bottom: 0; align-items: flex-end; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }

.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: start; }
.disk { display: flex; flex-direction: column; gap: 10px; }
.disk-line { display: flex; align-items: center; justify-content: space-between; }

.cache-row { display: flex; align-items: center; gap: 14px; }

@media (max-width: 1100px) {
  .grid-2 { grid-template-columns: minmax(0, 1fr); }
}
</style>
