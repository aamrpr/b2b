import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { toMinor } from '@/lib/money'
import { Button, Card, Field, Input, PageTitle, Select, Textarea } from '@/components/ui'
import type { DisputeDetail } from '@/types'

export function CreateDispute() {
  const { orderId = '' } = useParams()
  const [params] = useSearchParams()
  const { t } = useI18n()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ dispute: DisputeDetail }>(`/orders/${orderId}/disputes`, payload)).data.dispute,
    onSuccess: (dispute) => navigate(`/returns/disputes/${dispute.id}`),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const reasons = t.disputes.reasons as Record<string, string>
  const returnId = params.get('return')

  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title={t.disputes.newDispute} />

      <form
        onSubmit={(event) => {
          event.preventDefault()
          const form = new FormData(event.currentTarget)

          create.mutate({
            reason: form.get('reason'),
            description: form.get('description'),
            claimed_amount: toMinor(String(form.get('claimed_amount') ?? '0')),
            return_request_id: returnId ? Number(returnId) : undefined,
          })
        }}
      >
        <Card className="space-y-4">
          <Field label={t.disputes.title}>
            <Select name="reason" defaultValue="quality" required>
              {Object.entries(reasons).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label={t.rfq.description}>
            <Textarea name="description" required className="min-h-32" />
          </Field>

          <Field label={t.disputes.claimedAmount}>
            <Input name="claimed_amount" type="number" step="0.01" min="0" className="tabular" />
          </Field>

          {error && <p className="text-sm text-danger">{error}</p>}

          <Button type="submit" className="w-full" disabled={create.isPending}>
            {create.isPending ? t.common.loading : t.disputes.newDispute}
          </Button>
        </Card>
      </form>
    </div>
  )
}
