import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { errorMessage } from '@/lib/api'
import { Button, Card, Field, Input } from '@/components/ui'

export function Login() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const login = useAuth((s) => s.login)
  // ?expired=1 يضعه اعتراض 401: الخروج لم يكن باختياره، فيُقال له لماذا
  const [params] = useSearchParams()
  const expired = params.get('expired') === '1'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email, password)
      navigate('/')
    } catch (err) {
      setError(errorMessage(err, t.common.error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-md">
      <Card>
        <h1 className="text-xl font-bold mb-6">{t.auth.loginTitle}</h1>

        {expired && (
          <p className="mb-5 rounded-lg bg-[var(--warning-soft)] px-4 py-3 text-sm text-[var(--text)]">
            {t.common.sessionExpired}
          </p>
        )}

        <form onSubmit={submit} className="space-y-4">
          <Field label={t.auth.email}>
            <Input type="email" dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </Field>

          <Field label={t.auth.password}>
            <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </Field>

          {error && <p className="text-sm text-danger">{error}</p>}

          <Button type="submit" className="w-full" disabled={busy}>
            {busy ? t.common.loading : t.auth.submit}
          </Button>
        </form>

        <p className="muted text-sm mt-5 text-center">
          {t.auth.noAccount}{' '}
          <Link to="/register" className="text-accent font-medium">
            {t.nav.register}
          </Link>
        </p>
      </Card>
    </div>
  )
}
