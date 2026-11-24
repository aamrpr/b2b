import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'

export interface UnitOption {
  code: string
  name: string
  is_active: boolean
}

/** وحدات القياس باسمها في لغة الواجهة. الرمز هو ما يُخزَّن مع المنتج. */
export function useUnits() {
  const { locale } = useI18n()

  const query = useQuery({
    queryKey: ['units', locale],
    queryFn: async () => (await api.get<{ data: UnitOption[] }>('/units')).data.data,
    staleTime: 60_000,
  })

  const label = (code?: string | null) => {
    if (!code) return ''

    const key = code.trim().toLowerCase()

    return query.data?.find((unit) => unit.code === key)?.name ?? code
  }

  return { units: query.data ?? [], label, isLoading: query.isLoading }
}
