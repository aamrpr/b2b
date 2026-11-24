import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  BadgeCheck,
  BookOpenCheck,
  Building2,
  FileSignature,
  type LucideIcon,
  MessageCircle,
  PenLine,
  Scale,
  ScrollText,
  Send,
  Store,
  Table2,
  Users,
} from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { PhoneField } from '@/components/fields/PhoneField'
import { Button, Card, Field, Input, PageTitle, Textarea } from '@/components/ui'

/**
 * صفحة «عن المنصة».
 *
 * ليست تعريفاً بالشركة بل شرحاً للطريق: ما المشكلة، وكيف تُحلّ، وما
 * الطريقان إلى الصفقة، وما الذي يحمي الطرفين — ثمّ بابٌ للتواصل في
 * آخرها، لأنّ من قرأ ولم يقتنع يسأل.
 *
 * والتواصل بابان: نموذجٌ يصل إدارة المنصة (يعمل للزائر قبل أن يسجّل)،
 * ومحادثةٌ داخل المنصة لمن سجّل — وهي أسرع لأنّ سجلّها يبقى معه.
 */
export function About() {
  const { t } = useI18n()
  const user = useAuth((s) => s.user)
  const [error, setError] = useState<string | null>(null)
  const [sent, setSent] = useState(false)

  const send = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/contact', payload)).data,
    onSuccess: () => {
      setSent(true)
      setError(null)
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    send.mutate({
      name: form.get('name'),
      email: form.get('email'),
      phone: form.get('phone') || undefined,
      subject: form.get('subject'),
      message: form.get('message'),
    })
  }

  return (
    <div className="space-y-12">
      <header className="max-w-3xl">
        <PageTitle title={t.about.title} />
        <p className="text-lg leading-relaxed muted">{t.about.lead}</p>
      </header>

      <section className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="mb-3 text-lg font-bold">{t.about.problemTitle}</h2>
          <p className="leading-relaxed muted">{t.about.problemBody}</p>
        </Card>

        <Card>
          <h2 className="mb-3 text-lg font-bold">{t.about.answerTitle}</h2>
          <ul className="space-y-3 text-sm">
            {(
              [
                [Store, t.about.answers.directory],
                [Send, t.about.answers.rfq],
                [Table2, t.about.answers.compare],
                [BookOpenCheck, t.about.answers.ledger],
              ] as [LucideIcon, string][]
            ).map(([Icon, text]) => (
              <li key={text} className="flex gap-2.5">
                <Icon size={16} className="mt-0.5 shrink-0 text-accent" />
                <span className="leading-relaxed">{text}</span>
              </li>
            ))}
          </ul>
        </Card>
      </section>

      <section>
        <h2 className="mb-5 text-xl font-bold">{t.about.pathsTitle}</h2>

        <div className="grid gap-5 md:grid-cols-2">
          {[
            { title: t.about.pathA, icon: MessageCircle, steps: t.about.pathASteps },
            { title: t.about.pathB, icon: Send, steps: t.about.pathBSteps },
          ].map(({ title, icon: Icon, steps }) => (
            <Card key={title}>
              <div className="mb-4 flex items-center gap-2.5">
                <span className="grid h-9 w-9 place-items-center rounded-xl bg-[var(--accent-soft)] text-accent">
                  <Icon size={17} />
                </span>
                <h3 className="font-semibold">{title}</h3>
              </div>

              <ol className="space-y-3">
                {[steps.one, steps.two, steps.three].map((step, index) => (
                  <li key={step} className="flex gap-3 text-sm">
                    <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[var(--surface)] text-xs font-bold tabular text-accent">
                      {index + 1}
                    </span>
                    <span className="leading-relaxed">{step}</span>
                  </li>
                ))}
              </ol>
            </Card>
          ))}
        </div>
      </section>

      <section>
        <h2 className="mb-5 text-xl font-bold">{t.about.forWhoTitle}</h2>

        <div className="grid gap-5 md:grid-cols-2">
          {[
            { title: t.about.forImporter, body: t.about.forImporterBody, icon: Building2 },
            { title: t.about.forBuyer, body: t.about.forBuyerBody, icon: Users },
          ].map(({ title, body, icon: Icon }) => (
            <Card key={title}>
              <div className="mb-3 flex items-center gap-2.5">
                <Icon size={18} className="text-accent" />
                <h3 className="font-semibold">{title}</h3>
              </div>
              <p className="leading-relaxed muted">{body}</p>
            </Card>
          ))}
        </div>
      </section>

      <section>
        <h2 className="mb-5 text-xl font-bold">{t.about.trustTitle}</h2>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {(
            [
              [BadgeCheck, t.about.trust.verification],
              [FileSignature, t.about.trust.signature],
              [Scale, t.about.trust.dispute],
              [ScrollText, t.about.trust.audit],
            ] as [LucideIcon, string][]
          ).map(([Icon, text]) => (
            <Card key={text}>
              <Icon size={18} className="mb-2.5 text-accent" />
              <p className="text-sm leading-relaxed">{text}</p>
            </Card>
          ))}
        </div>
      </section>

      <section id="contact" className="scroll-mt-20 grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card>
          <h2 className="text-lg font-bold">{t.about.contactTitle}</h2>
          <p className="muted mt-1 mb-5 text-sm">{t.about.contactLead}</p>

          {sent ? (
            <p className="rounded-lg bg-[var(--success-soft)] px-4 py-3 text-sm text-success">
              {t.about.contactSent}
            </p>
          ) : (
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
              <Field label={t.about.contactName}>
                <Input name="name" required defaultValue={user?.name ?? ''} />
              </Field>
              <Field label={t.about.contactEmail}>
                <Input name="email" type="email" dir="ltr" required defaultValue={user?.email ?? ''} />
              </Field>
              <Field label={t.about.contactPhone}>
                <PhoneField name="phone" defaultValue={user?.phone ?? ''} />
              </Field>
              <Field label={t.about.contactSubject}>
                <Input name="subject" required />
              </Field>

              <div className="sm:col-span-2">
                <Field label={t.about.contactMessage}>
                  <Textarea name="message" rows={5} required />
                </Field>
              </div>

              <div className="sm:col-span-2">
                <Button type="submit" disabled={send.isPending}>
                  <PenLine size={16} />
                  {send.isPending ? t.common.loading : t.about.contactSend}
                </Button>

                {error && <p className="mt-3 text-sm text-danger">{error}</p>}
              </div>
            </form>
          )}
        </Card>

        <Card className="h-fit">
          <h3 className="mb-3 font-semibold">{t.about.channelsTitle}</h3>
          <p className="muted mb-4 text-sm leading-relaxed">{t.about.insideChat}</p>

          <Link to={user ? '/messages' : '/login'}>
            <Button variant="outline" size="sm" className="w-full">
              <MessageCircle size={15} />
              {t.about.openChat}
            </Button>
          </Link>
        </Card>
      </section>
    </div>
  )
}
