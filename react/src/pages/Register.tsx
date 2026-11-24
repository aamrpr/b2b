import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { Button, Card, Field, Input, PageTitle } from '@/components/ui'
import type { Category } from '@/types'
import { PhoneField } from '@/components/fields/PhoneField'

type Mode = 'supplier' | 'buyer'

export function Register() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const { registerSupplier, registerBuyer } = useAuth()

  const [mode, setMode] = useState<Mode>('supplier')
  const [categoryIds, setCategoryIds] = useState<number[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data,
  })

  function toggleCategory(id: number) {
    setCategoryIds((current) => (current.includes(id) ? current.filter((c) => c !== id) : [...current, id]))
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const payload = Object.fromEntries(form) as Record<string, unknown>

    setBusy(true)
    setError(null)
    try {
      if (mode === 'supplier') {
        await registerSupplier({ ...payload, category_ids: categoryIds })
      } else {
        await registerBuyer(payload)
      }
      navigate('/')
    } catch (err) {
      setError(errorMessage(err, t.common.error))
    } finally {
      setBusy(false)
    }
  }

  const needsCategory = mode === 'supplier' && categoryIds.length === 0

  return (
    <div className="mx-auto max-w-2xl">
      <PageTitle title={t.nav.register} subtitle={t.auth.registerHint} />

      <Card>
        <div className="flex gap-2 mb-6">
          {(['supplier', 'buyer'] as Mode[]).map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => setMode(option)}
              className={clsx(
                'flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition',
                mode === option
                  ? 'border-brand-500 bg-brand-500/10 text-accent'
                  : 'border-[var(--border)] muted hover:bg-[var(--surface)]',
              )}
            >
              {option === 'supplier' ? t.auth.asSupplier : t.auth.asBuyer}
            </button>
          ))}
        </div>

        <form onSubmit={submit} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t.auth.companyName}>
              <Input name="company_name" required />
            </Field>

            {mode === 'supplier' && (
              <Field label={t.auth.companyNameEn}>
                <Input name="company_name_en" dir="ltr" />
              </Field>
            )}

            <Field label={t.auth.contactName}>
              <Input name="contact_name" required />
            </Field>

            <Field label={t.auth.phone}>
              <PhoneField name="phone" required />
            </Field>

            <Field label={t.auth.email}>
              <Input name="email" type="email" dir="ltr" required />
            </Field>

            <Field label={t.auth.city}>
              <Input name="city" />
            </Field>

            <Field label={t.auth.password}>
              <Input name="password" type="password" minLength={8} required />
            </Field>

            <Field label={t.auth.passwordConfirm} hint={t.auth.passwordRule}>
              <Input name="password_confirmation" type="password" minLength={8} required />
            </Field>
          </div>

          {mode === 'supplier' && (
            <Field label={t.auth.categories} hint={t.auth.categoriesHint}>
              <div className="flex flex-wrap gap-2 mt-1">
                {categories?.map((category) => (
                  <button
                    key={category.id}
                    type="button"
                    onClick={() => toggleCategory(category.id)}
                    className={clsx(
                      'rounded-full border px-3 py-1.5 text-sm transition',
                      categoryIds.includes(category.id)
                        ? 'border-brand-500 bg-brand-500/10 text-accent'
                        : 'border-[var(--border)] muted hover:bg-[var(--surface)]',
                    )}
                  >
                    {category.name}
                  </button>
                ))}
              </div>
            </Field>
          )}

          {error && <p className="text-sm text-danger">{error}</p>}

          {/* الزرّ معطّل لسببٍ، فيُقال السبب بدل أن يُترك المستخدم يخمّن */}
          {needsCategory && <p className="muted text-sm">{t.auth.pickCategory}</p>}

          <Button type="submit" className="w-full" disabled={busy || needsCategory}>
            {busy ? t.common.loading : mode === 'supplier' ? t.auth.registerSupplier : t.auth.registerBuyer}
          </Button>
        </form>

        <p className="muted text-sm mt-5 text-center">
          {t.auth.haveAccount}{' '}
          <Link to="/login" className="text-accent font-medium">
            {t.nav.login}
          </Link>
        </p>
      </Card>
    </div>
  )
}
