import { Check, Circle, MapPin } from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { formatDateTime } from '@/lib/date'

interface TimelineEvent {
  status: string
  location: string | null
  description: string | null
  occurred_at: string | null
  recorded_by?: string | null
}

/** سلسلة التتبّع. الأحدث أعلى، والأقدم أسفل — كما يقرأها المتتبِّع. */
export function ShipmentTimeline({ events }: { events: TimelineEvent[] }) {
  const { t, locale } = useI18n()
  const statuses = t.shipping.statuses as Record<string, string>

  if (events.length === 0) {
    return <p className="muted text-sm">{t.common.empty}</p>
  }

  return (
    <ol className="relative">
      {events.map((event, index) => {
        const isLatest = index === 0
        const isDelivered = event.status === 'delivered'

        return (
          <li key={`${event.status}-${event.occurred_at}-${index}`} className="flex gap-3 pb-6 last:pb-0">
            <div className="flex flex-col items-center">
              <span
                className={clsx(
                  'grid h-7 w-7 shrink-0 place-items-center rounded-full border-2',
                  isDelivered
                    ? 'border-emerald-700 bg-emerald-700 text-white'
                    : isLatest
                      ? 'border-brand-700 bg-brand-700 text-white'
                      : 'border-[var(--border)] bg-[var(--card)] muted',
                )}
              >
                {isDelivered ? <Check size={14} /> : <Circle size={9} fill="currentColor" />}
              </span>

              {index < events.length - 1 && <span className="w-px flex-1 bg-[var(--border)] mt-1" />}
            </div>

            <div className="min-w-0 flex-1 -mt-0.5">
              <div className="flex flex-wrap items-center gap-2">
                <span className={clsx('font-medium text-sm', isLatest && 'text-accent')}>
                  {statuses[event.status] ?? event.status}
                </span>
                {event.location && (
                  <span className="flex items-center gap-1 text-xs muted">
                    <MapPin size={11} />
                    {event.location}
                  </span>
                )}
              </div>

              {event.description && <p className="muted text-sm mt-0.5">{event.description}</p>}

              <div className="muted text-xs mt-1 tabular">
                {event.occurred_at ? formatDateTime(event.occurred_at, locale) : ''}
                {event.recorded_by && ` · ${event.recorded_by}`}
              </div>
            </div>
          </li>
        )
      })}
    </ol>
  )
}
