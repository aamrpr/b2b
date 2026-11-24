import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Building2, ImagePlus, KeyRound, Trash2, UserRound } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { Button, Card, Field, Input, PageTitle, Select, Textarea } from '@/components/ui'
import type { AuthUser } from '@/types'
import { PhoneField } from '@/components/fields/PhoneField'
import { SupplierLogo } from '@/components/SupplierLogo'

/**
 * حساب المستخدم.
 *
 * كل مستخدم يدير حسابه بنفسه: معلوماته، صورته، كلمة مروره. وصاحب
 * الشركة يجد تحتها بيانات شركته وشعارها — والشعار ليس زينة: هو ما
 * يميّز المستورد في الدليل وفي كل عرض سعر يرسله.
 */
export function Account() {
  const { t, locale, setLocale } = useI18n()
  const user = useAuth((s) => s.user)
  const setUser = useAuth((s) => s.setUser)
  const [error, setError] = useState<string | null>(null)
  const [passwordError, setPasswordError] = useState<string | null>(null)

  const avatarInput = useRef<HTMLInputElement>(null)
  const logoInput = useRef<HTMLInputElement>(null)

  const tenant = user?.tenant
  const isCompany = Boolean(tenant) && tenant?.type !== 'platform'

  /** الإشعار يأتي من الخادم عبر الاعتراض، فلا يبقى هنا إلّا تحديث المستخدم */
  function applyUser(next: AuthUser) {
    setUser(next)
    setError(null)
  }

  const saveProfile = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.put<{ user: AuthUser }>('/me', payload)).data.user,
    onSuccess: (next) => {
      applyUser(next)
      // اللغة تفضيلٌ محفوظ في الحساب وفي المتصفّح معاً، فتتبع إحداهما الأخرى
      if (next.locale !== locale) setLocale(next.locale as 'ar' | 'en')
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const savePassword = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.put('/me/password', payload)).data,
    onSuccess: () => {
      setPasswordError(null)
    },
    onError: (err) => setPasswordError(errorMessage(err, t.common.error)),
  })

  const saveCompany = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.put<{ user: AuthUser }>('/my/company', payload)).data.user,
    onSuccess: (next) => applyUser(next),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const uploadImage = useMutation({
    mutationFn: async ({ field, file }: { field: 'avatar' | 'logo'; file: File }) => {
      const form = new FormData()
      form.append(field, file)

      const url = field === 'avatar' ? '/me/avatar' : '/my/company/logo'

      return (await api.post<{ user: AuthUser }>(url, form)).data.user
    },
    onSuccess: (next) => applyUser(next),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const removeImage = useMutation({
    mutationFn: async (field: 'avatar' | 'logo') =>
      (await api.delete<{ user: AuthUser }>(field === 'avatar' ? '/me/avatar' : '/my/company/logo')).data.user,
    onSuccess: (next) => applyUser(next),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (!user) return null

  function submitProfile(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    saveProfile.mutate({
      name: form.get('name'),
      email: form.get('email'),
      phone: form.get('phone') || null,
      locale: form.get('locale'),
    })
  }

  function submitPassword(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const data = new FormData(form)

    savePassword.mutate(
      {
        current_password: data.get('current_password'),
        password: data.get('password'),
        password_confirmation: data.get('password_confirmation'),
      },
      { onSuccess: () => form.reset() },
    )
  }

  function submitCompany(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const about = String(form.get('about') ?? '').trim()

    saveCompany.mutate({
      name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
      description: about ? { ...(tenant?.description_translations ?? {}), ar: about } : null,
      city: form.get('city') || null,
      phone: form.get('phone') || null,
      whatsapp: form.get('whatsapp') || null,
    })
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageTitle title={t.account.title} subtitle={user.email} />

      {error && <div className="card p-4 text-sm text-danger">{error}</div>}

      <Card>
        <SectionTitle icon={UserRound} title={t.account.profile} />

        <div className="mb-5 flex flex-wrap items-center gap-4">
          <SupplierLogo src={user.avatar_url} name={user.name} size="lg" square={false} />

          <div className="flex gap-2">
            <input
              ref={avatarInput}
              type="file"
              accept="image/*"
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0]
                if (file) uploadImage.mutate({ field: 'avatar', file })
                event.target.value = ''
              }}
            />
            <Button type="button" variant="outline" size="sm" onClick={() => avatarInput.current?.click()}>
              <ImagePlus size={15} />
              {t.account.upload}
            </Button>
            {user.avatar_url && (
              <Button type="button" variant="ghost" size="sm" onClick={() => removeImage.mutate('avatar')}>
                <Trash2 size={15} className="text-danger" />
                {t.account.removeImage}
              </Button>
            )}
          </div>
        </div>

        <form onSubmit={submitProfile} className="grid gap-4 sm:grid-cols-2">
          <Field label={t.account.name}>
            <Input name="name" required defaultValue={user.name} />
          </Field>
          <Field label={t.account.email}>
            <Input name="email" type="email" required dir="ltr" defaultValue={user.email} />
          </Field>
          <Field label={t.account.phone}>
            <PhoneField name="phone" defaultValue={user.phone ?? ''} />
          </Field>
          <Field label={t.account.language}>
            <Select name="locale" defaultValue={user.locale}>
              <option value="ar">العربية</option>
              <option value="en">English</option>
            </Select>
          </Field>

          <div className="sm:col-span-2">
            <Button type="submit" disabled={saveProfile.isPending}>
              {saveProfile.isPending ? t.common.loading : t.common.save}
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <SectionTitle icon={KeyRound} title={t.account.password} />
        <p className="muted mb-4 text-xs">{t.account.passwordHint}</p>

        <form onSubmit={submitPassword} className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Field label={t.account.currentPassword}>
              <Input name="current_password" type="password" required autoComplete="current-password" />
            </Field>
          </div>
          <Field label={t.account.newPassword}>
            <Input name="password" type="password" required minLength={8} autoComplete="new-password" />
          </Field>
          <Field label={t.account.confirmPassword}>
            <Input name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" />
          </Field>

          <div className="sm:col-span-2">
            <Button type="submit" disabled={savePassword.isPending}>
              {savePassword.isPending ? t.common.loading : t.account.changePassword}
            </Button>
            {passwordError && <p className="mt-3 text-sm text-danger">{passwordError}</p>}
          </div>
        </form>
      </Card>

      {isCompany && (
        <Card>
          <SectionTitle icon={Building2} title={t.account.company} />

          <div className="mb-5 flex flex-wrap items-center gap-4">
            <SupplierLogo src={tenant?.logo_url} name={tenant?.name ?? ''} size="lg" />

            <div className="flex gap-2">
              <input
                ref={logoInput}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file) uploadImage.mutate({ field: 'logo', file })
                  event.target.value = ''
                }}
              />
              <Button type="button" variant="outline" size="sm" onClick={() => logoInput.current?.click()}>
                <ImagePlus size={15} />
                {t.account.upload}
              </Button>
              {tenant?.logo_url && (
                <Button type="button" variant="ghost" size="sm" onClick={() => removeImage.mutate('logo')}>
                  <Trash2 size={15} className="text-danger" />
                  {t.account.removeImage}
                </Button>
              )}
            </div>
          </div>

          <form onSubmit={submitCompany} className="grid gap-4 sm:grid-cols-2">
            <Field label={t.account.companyName}>
              <Input name="name_ar" required defaultValue={tenant?.name_translations?.ar ?? tenant?.name ?? ''} />
            </Field>
            <Field label={t.account.companyNameEn}>
              <Input name="name_en" dir="ltr" defaultValue={tenant?.name_translations?.en ?? ''} />
            </Field>
            <Field label={t.common.city}>
              <Input name="city" defaultValue={tenant?.city ?? ''} />
            </Field>
            <Field label={t.account.phone}>
              <PhoneField name="phone" defaultValue={tenant?.phone ?? ''} />
            </Field>
            <Field label={t.account.whatsapp}>
              <PhoneField name="whatsapp" defaultValue={tenant?.whatsapp ?? ''} />
            </Field>

            <div className="sm:col-span-2">
              <Field label={t.account.about}>
                <Textarea name="about" rows={3} defaultValue={tenant?.description_translations?.ar ?? ''} />
              </Field>
            </div>

            <div className="sm:col-span-2">
              <Button type="submit" disabled={saveCompany.isPending}>
                {saveCompany.isPending ? t.common.loading : t.common.save}
              </Button>
            </div>
          </form>
        </Card>
      )}
    </div>
  )
}

function SectionTitle({ icon: Icon, title }: { icon: typeof UserRound; title: string }) {
  return (
    <div className="mb-4 flex items-center gap-2">
      <span className="grid h-8 w-8 place-items-center rounded-lg bg-[var(--accent-soft)] text-accent">
        <Icon size={16} />
      </span>
      <h2 className="font-semibold">{title}</h2>
    </div>
  )
}

