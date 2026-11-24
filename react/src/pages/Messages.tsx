import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { Check, CheckCheck, FileText, Image as ImageIcon, Mic, Paperclip, Play, Send, Video, X } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatDayMonth } from '@/lib/date'
import { useAuth } from '@/store/auth'
import { useRealtime } from '@/store/realtime'
import { Button, Card, Empty, ErrorState, Loading, PageTitle } from '@/components/ui'
import { captureImageThumb, capturePdfPoster, captureVideoFrame } from '@/lib/mediaThumb'
import type { Conversation, Message } from '@/types'

type MediaKind = 'image' | 'video' | 'pdf' | 'audio' | 'file'

interface PendingFile {
  kind: MediaKind
  file: File
  url: string | null
  thumb: File | null
}

interface MediaView {
  kind: 'image' | 'video' | 'pdf'
  url: string
  name: string
}

const LIMITS: Record<MediaKind, number> = {
  image: 8 * 1024 * 1024,
  audio: 15 * 1024 * 1024,
  video: 50 * 1024 * 1024,
  pdf: 15 * 1024 * 1024,
  file: 15 * 1024 * 1024,
}

const ACCEPT: Record<Exclude<MediaKind, 'audio'>, string> = {
  image: 'image/jpeg,image/png,image/webp,image/gif',
  video: 'video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov',
  pdf: 'application/pdf,.pdf',
  file: '.xlsx,.xls,.csv,.docx',
}

const MAX_RECORDING_SECONDS = 180

function dayLabel(iso: string | null, locale: string, t: { today: string; yesterday: string }): string {
  if (!iso) return ''

  const date = new Date(iso)
  const today = new Date()
  const diffDays = Math.floor((today.setHours(0, 0, 0, 0) - new Date(iso).setHours(0, 0, 0, 0)) / 86_400_000)

  if (diffDays === 0) return t.today
  if (diffDays === 1) return t.yesterday

  return formatDayMonth(date, locale)
}

function clockTime(iso: string | null, locale: string): string {
  if (!iso) return ''

  return new Date(iso).toLocaleTimeString(locale === 'ar' ? 'ar-SY' : 'en-US', {
    hour: '2-digit',
    minute: '2-digit',
    numberingSystem: 'latn',
  })
}

function recordingClock(seconds: number): string {
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60

  return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
}

function mediaKind(message: { type?: string; attachment_url?: string | null; attachment_mime?: string | null; attachment_name?: string | null }): MediaKind | null {
  const mime = message.attachment_mime ?? ''
  const name = (message.attachment_name ?? '').toLowerCase()
  const url = (message.attachment_url ?? '').split('?')[0].toLowerCase()

  if (message.type === 'image' || mime.startsWith('image/')) return 'image'
  if (message.type === 'audio' || mime.startsWith('audio/')) return 'audio'
  if (message.type === 'video' || mime.startsWith('video/')) return 'video'
  if (mime === 'application/pdf' || name.endsWith('.pdf') || url.endsWith('.pdf')) return 'pdf'
  if (message.attachment_url || message.type === 'file') return 'file'

  return null
}

function previewLabel(
  message: { body: string | null; type?: string; attachment_mime?: string | null } | null | undefined,
  labels: { image: string; voice: string; video: string; pdf: string; attachment: string },
): string {
  if (!message) return ''
  if (message.body) return message.body

  const kind = mediaKind(message)

  if (kind === 'image') return labels.image
  if (kind === 'audio') return labels.voice
  if (kind === 'video') return labels.video
  if (kind === 'pdf') return labels.pdf

  return labels.attachment
}

function ThumbButton({
  src,
  name,
  kind,
  onClick,
}: {
  src?: string | null
  name: string
  kind: 'image' | 'video' | 'pdf'
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="relative mt-2 block overflow-hidden rounded-xl text-start"
      aria-label={name}
    >
      {src ? (
        <img
          src={src}
          alt=""
          className={clsx('bg-black/20 object-cover', kind === 'video' ? 'h-36 w-56' : 'h-40 w-32')}
        />
      ) : (
        <span
          className={clsx(
            'grid place-items-center bg-navy-800 text-white',
            kind === 'video' ? 'h-36 w-56' : 'h-40 w-32',
          )}
        >
          {kind === 'pdf' ? <FileText size={28} /> : <Play size={28} />}
        </span>
      )}

      {kind === 'video' && (
        <span className="absolute inset-0 grid place-items-center bg-black/25">
          <span className="grid h-10 w-10 place-items-center rounded-full bg-white/90 text-navy-900">
            <Play size={18} className="ms-0.5" fill="currentColor" />
          </span>
        </span>
      )}

      {kind === 'pdf' && (
        <span className="absolute inset-x-0 bottom-0 truncate bg-black/60 px-2 py-1 text-[11px] text-white">
          {name}
        </span>
      )}
    </button>
  )
}

function MediaViewer({ view, closeLabel, onClose }: { view: MediaView; closeLabel: string; onClose: () => void }) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-black/75 p-4" onClick={onClose} role="presentation">
      <div
        className="relative max-h-[90vh] w-full max-w-4xl"
        role="dialog"
        aria-modal="true"
        aria-label={view.name}
        onClick={(event) => event.stopPropagation()}
      >
        <button
          type="button"
          onClick={onClose}
          className="absolute -top-11 end-0 grid h-9 w-9 place-items-center rounded-full text-white hover:bg-white/15"
          aria-label={closeLabel}
        >
          <X size={20} />
        </button>

        {view.kind === 'image' && (
          <img src={view.url} alt={view.name} className="mx-auto max-h-[85vh] max-w-full rounded-lg object-contain" />
        )}

        {view.kind === 'video' && (
          <video src={view.url} controls autoPlay playsInline className="max-h-[85vh] w-full rounded-lg bg-black" />
        )}

        {view.kind === 'pdf' && (
          <iframe title={view.name} src={view.url} className="h-[85vh] w-full rounded-lg bg-white" />
        )}
      </div>
    </div>
  )
}

function AttachmentView({
  message,
  voiceLabel,
  fileLabel,
  onOpen,
}: {
  message: Message
  voiceLabel: string
  fileLabel: string
  onOpen: (view: MediaView) => void
}) {
  const url = message.attachment_url
  if (!url) return null

  const kind = mediaKind(message)
  const name = message.attachment_name || fileLabel

  if (kind === 'image' || kind === 'video' || kind === 'pdf') {
    return (
      <ThumbButton
        src={message.thumbnail_url || (kind === 'image' ? url : null)}
        name={name}
        kind={kind}
        onClick={() => onOpen({ kind, url, name })}
      />
    )
  }

  if (kind === 'audio') {
    return (
      <div className="mt-2 min-w-0">
        <div className="mb-1 text-xs opacity-80">{voiceLabel}</div>
        <audio controls preload="metadata" src={url} className="h-10 w-full min-w-0" />
      </div>
    )
  }

  return (
    <a href={url} target="_blank" rel="noreferrer" className="mt-1.5 flex items-center gap-1.5 text-xs underline">
      <Paperclip size={13} />
      <span className="truncate">{name}</span>
    </a>
  )
}

export function Messages() {
  const { conversationId } = useParams()
  const { t, locale } = useI18n()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const { lastMessage, lastTyping, lastRead, consumeMessage, consumeTyping, consumeRead, refreshCounts } = useRealtime()

  const [draft, setDraft] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState<PendingFile | null>(null)
  const [preparing, setPreparing] = useState(false)
  const [viewer, setViewer] = useState<MediaView | null>(null)
  const [menuOpen, setMenuOpen] = useState(false)
  const [recording, setRecording] = useState(false)
  const [elapsed, setElapsed] = useState(0)
  const [typingAt, setTypingAt] = useState<Record<number, number>>({})
  const fileRef = useRef<HTMLInputElement>(null)
  const endRef = useRef<HTMLDivElement>(null)
  const pickKind = useRef<Exclude<MediaKind, 'audio'>>('file')
  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const discardRef = useRef(false)
  const stopRef = useRef<(discard: boolean) => void>(() => {})
  const pendingRef = useRef<PendingFile | null>(null)
  const lastTypingPing = useRef(0)
  const typingStop = useRef<number | null>(null)
  pendingRef.current = pending

  const activeId = conversationId ? Number(conversationId) : null
  const typingHere = activeId !== null && typingAt[activeId] !== undefined

  const { data: conversations, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['conversations'],
    queryFn: async () => (await api.get<{ data: Conversation[] }>('/conversations')).data.data,
  })

  const { data: messages } = useQuery({
    queryKey: ['messages', activeId],
    queryFn: async () =>
      (await api.get<{ data: Message[] }>(`/conversations/${activeId}/messages`)).data.data.slice().reverse(),
    enabled: activeId !== null,
  })

  const messageCount = messages?.length ?? 0

  stopRef.current = (discard: boolean) => {
    discardRef.current = discard
    const recorder = recorderRef.current

    if (recorder && recorder.state === 'recording') recorder.stop()
    else streamRef.current?.getTracks().forEach((track) => track.stop())

    setRecording(false)
  }

  useEffect(() => {
    if (!lastMessage) return

    if (lastMessage.conversation_id === activeId) {
      void queryClient.invalidateQueries({ queryKey: ['messages', activeId] })
      void api.post(`/conversations/${activeId}/read`).then(() => refreshCounts())
    }

    void queryClient.invalidateQueries({ queryKey: ['conversations'] })
    consumeMessage()
  }, [lastMessage, activeId, queryClient, consumeMessage, refreshCounts])

  useEffect(() => {
    if (!lastTyping) return

    const event = lastTyping
    consumeTyping()

    if (event.tenant_id === user?.tenant?.id) return

    setTypingAt((current) => {
      if (!event.typing) {
        if (current[event.conversation_id] === undefined) return current
        const next = { ...current }
        delete next[event.conversation_id]
        return next
      }

      return { ...current, [event.conversation_id]: event.at }
    })
  }, [lastTyping, consumeTyping, user?.tenant?.id])

  useEffect(() => {
    if (!lastRead) return

    const event = lastRead
    consumeRead()

    if (event.tenant_id === user?.tenant?.id) return

    queryClient.setQueryData<Message[]>(['messages', event.conversation_id], (current) =>
      current?.map((message) =>
        message.is_mine && message.id <= event.message_id ? { ...message, seen: true } : message,
      ),
    )
  }, [lastRead, consumeRead, queryClient, user?.tenant?.id])

  useEffect(() => {
    const timer = window.setInterval(() => {
      setTypingAt((current) => {
        const now = Date.now()
        let changed = false
        const next: Record<number, number> = {}

        for (const [id, at] of Object.entries(current)) {
          if (now - at < 2500) next[Number(id)] = at
          else changed = true
        }

        return changed ? next : current
      })
    }, 800)

    return () => window.clearInterval(timer)
  }, [])

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messageCount, typingHere])

  useEffect(() => {
    if (!activeId || messageCount === 0) return

    void queryClient.invalidateQueries({ queryKey: ['conversations'] })
    void refreshCounts()
  }, [activeId, messageCount, queryClient, refreshCounts])

  useEffect(() => {
    if (!recording) return

    const started = Date.now()
    const timer = window.setInterval(() => {
      const seconds = Math.floor((Date.now() - started) / 1000)
      setElapsed(seconds)
      if (seconds >= MAX_RECORDING_SECONDS) stopRef.current(false)
    }, 250)

    return () => window.clearInterval(timer)
  }, [recording])

  useEffect(() => {
    setDraft('')
    setError(null)
    setMenuOpen(false)
    stopRef.current(true)
    setPending((current) => {
      if (current?.url) URL.revokeObjectURL(current.url)
      return null
    })

    return () => {
      streamRef.current?.getTracks().forEach((track) => track.stop())
      if (pendingRef.current?.url) URL.revokeObjectURL(pendingRef.current.url)
      if (activeId) void api.post(`/conversations/${activeId}/typing`, { typing: false }).catch(() => {})
    }
  }, [activeId])

  const send = useMutation({
    mutationFn: async (payload: { body: string; file: PendingFile | null }) => {
      if (payload.file) {
        const form = new FormData()
        if (payload.body) form.append('body', payload.body)
        form.append('attachment', payload.file.file)
        form.append('kind', payload.file.kind === 'pdf' ? 'file' : payload.file.kind)
        if (payload.file.thumb) form.append('thumbnail', payload.file.thumb)

        return (await api.post(`/conversations/${activeId}/messages`, form)).data
      }

      return (await api.post(`/conversations/${activeId}/messages`, { body: payload.body })).data
    },
    onSuccess: (_data, variables) => {
      setDraft('')
      if (typingStop.current) window.clearTimeout(typingStop.current)
      lastTypingPing.current = 0
      if (activeId) void api.post(`/conversations/${activeId}/typing`, { typing: false }).catch(() => {})
      if (variables.file?.url) URL.revokeObjectURL(variables.file.url)
      setPending(null)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['messages', activeId] })
      void queryClient.invalidateQueries({ queryKey: ['conversations'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function pulseTyping(active: boolean) {
    if (!activeId) return
    void api.post(`/conversations/${activeId}/typing`, { typing: active }).catch(() => {})
  }

  function onDraftChange(value: string) {
    setDraft(value)
    if (typingStop.current) window.clearTimeout(typingStop.current)

    if (!value.trim()) {
      if (lastTypingPing.current !== 0) pulseTyping(false)
      lastTypingPing.current = 0
      return
    }

    const now = Date.now()
    if (now - lastTypingPing.current > 1500) {
      lastTypingPing.current = now
      pulseTyping(true)
    }

    typingStop.current = window.setTimeout(() => {
      lastTypingPing.current = 0
      pulseTyping(false)
    }, 1600)
  }

  function clearPending() {
    setPending((current) => {
      if (current?.url) URL.revokeObjectURL(current.url)
      return null
    })
  }

  function submit() {
    const body = draft.trim()
    if (recording || send.isPending || preparing || (!body && !pending)) return
    send.mutate({ body, file: pending })
  }

  function choose(kind: Exclude<MediaKind, 'audio'>) {
    const input = fileRef.current
    if (!input || send.isPending) return

    pickKind.current = kind
    input.accept = ACCEPT[kind]
    input.value = ''
    input.click()
    setMenuOpen(false)
  }

  async function onPicked(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    const kind = file.type.startsWith('image/')
      ? 'image'
      : file.type.startsWith('video/')
        ? 'video'
        : file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')
          ? 'pdf'
          : pickKind.current

    if (file.size > LIMITS[kind]) {
      setError(t.messages.tooLarge)
      return
    }

    setError(null)
    setPreparing(true)

    try {
      let thumb: File | null = null

      if (kind === 'image' || kind === 'video' || kind === 'pdf') {
        try {
          thumb = kind === 'video'
            ? await captureVideoFrame(file)
            : kind === 'pdf'
              ? await capturePdfPoster(file)
              : await captureImageThumb(file)
        } catch {
          thumb = null
        }
      }

      const url = thumb
        ? URL.createObjectURL(thumb)
        : kind === 'image'
          ? URL.createObjectURL(file)
          : null

      setPending((current) => {
        if (current?.url) URL.revokeObjectURL(current.url)
        return { kind, file, url, thumb }
      })
    } finally {
      setPreparing(false)
    }
  }

  async function startRecording() {
    if (send.isPending || recording) return

    setError(null)

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setError(t.messages.micDenied)
      return
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      streamRef.current = stream
      const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/ogg']
      const mime = candidates.find((item) => MediaRecorder.isTypeSupported(item)) ?? ''
      const recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined)
      chunksRef.current = []
      discardRef.current = false

      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunksRef.current.push(event.data)
      }

      recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop())
        streamRef.current = null
        const chunks = chunksRef.current
        chunksRef.current = []
        if (discardRef.current || chunks.length === 0) return

        const type = (recorder.mimeType || 'audio/webm').split(';')[0] || 'audio/webm'
        const extension = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm'
        const blob = new Blob(chunks, { type })
        const file = new File([blob], `voice.${extension}`, { type })

        if (file.size > LIMITS.audio) {
          setError(t.messages.tooLarge)
          return
        }

        const url = URL.createObjectURL(blob)
        setPending((current) => {
          if (current?.url) URL.revokeObjectURL(current.url)
          return { kind: 'audio', file, url, thumb: null }
        })
      }

      recorderRef.current = recorder
      recorder.start()
      setElapsed(0)
      setRecording(true)
      setMenuOpen(false)
    } catch {
      streamRef.current?.getTracks().forEach((track) => track.stop())
      streamRef.current = null
      setError(t.messages.micDenied)
    }
  }

  const active = useMemo(
    () => conversations?.find((conversation) => conversation.id === activeId) ?? null,
    [conversations, activeId],
  )

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const labels = {
    image: t.messages.image,
    voice: t.messages.voice,
    video: t.messages.video,
    pdf: t.messages.pdf,
    attachment: t.messages.attachment,
  }

  return (
    <div>
      <PageTitle title={t.messages.title} />

      <div className="grid min-h-[32rem] gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <Card className="overflow-hidden p-0">
          {!conversations?.length ? (
            <p className="muted py-12 text-center text-sm">{t.messages.empty}</p>
          ) : (
            <div className="max-h-[32rem] overflow-y-auto">
              {conversations.map((conversation) => (
                <button
                  key={conversation.id}
                  type="button"
                  onClick={() => navigate(`/messages/${conversation.id}`)}
                  className={clsx(
                    'w-full border-b border-[var(--border)] px-4 py-3 text-start transition last:border-0',
                    conversation.id === activeId ? 'bg-brand-500/10' : 'hover:bg-[var(--surface)]',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-sm font-medium">
                      {conversation.counterpart?.name ?? '—'}
                    </span>
                    {conversation.unread_count > 0 && (
                      <span className="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-brand-700 px-1.5 text-[11px] font-bold text-white tabular">
                        {conversation.unread_count}
                      </span>
                    )}
                  </div>

                  {conversation.subject && <div className="muted mt-0.5 truncate text-xs">{conversation.subject}</div>}

                  <div className={clsx('mt-1 truncate text-xs', typingAt[conversation.id] ? 'text-accent' : 'muted')}>
                    {typingAt[conversation.id]
                      ? t.messages.typing
                      : <>
                          {conversation.last_message?.sender_tenant_id === user?.tenant?.id && `${t.messages.you}: `}
                          {previewLabel(conversation.last_message, labels)}
                        </>}
                  </div>
                </button>
              ))}
            </div>
          )}
        </Card>

        {activeId === null ? (
          <Empty message={t.messages.selectOne} />
        ) : (
          <Card className="flex flex-col p-0">
            <div className="border-b border-[var(--border)] px-4 py-3">
              <div className="font-semibold">{active?.counterpart?.name ?? '—'}</div>
              {typingHere ? (
                <div className="mt-0.5 text-xs text-accent">{t.messages.typing}</div>
              ) : active?.context && (
                <div className="muted mt-0.5 text-xs tabular">
                  {active.context.type} #{active.context.id}
                </div>
              )}
            </div>

            <div className="max-h-[32rem] min-h-[20rem] flex-1 space-y-3 overflow-y-auto p-4">
              {messages?.map((message, index) => {
                const previous = messages[index - 1]
                const showDay =
                  !previous ||
                  new Date(previous.created_at ?? 0).toDateString() !==
                    new Date(message.created_at ?? 0).toDateString()

                return (
                  <div key={message.id}>
                    {showDay && (
                      <div className="my-3 text-center">
                        <span className="muted rounded-full bg-[var(--surface)] px-2 py-1 text-xs">
                          {dayLabel(message.created_at, locale, t.messages)}
                        </span>
                      </div>
                    )}

                    <div className={clsx('flex', message.is_mine ? 'justify-end' : 'justify-start')}>
                      <div
                        className={clsx(
                          'max-w-[min(75%,24rem)] min-w-0 rounded-2xl px-4 py-2.5',
                          message.is_mine
                            ? 'rounded-ee-sm bg-brand-700 text-white'
                            : 'rounded-es-sm bg-[var(--surface)]',
                        )}
                      >
                        {!message.is_mine && message.sender_name && (
                          <div className="mb-1 text-xs opacity-70">{message.sender_name}</div>
                        )}

                        {message.body && <div className="break-words text-sm whitespace-pre-wrap">{message.body}</div>}

                        <AttachmentView
                          message={message}
                          voiceLabel={t.messages.voice}
                          fileLabel={t.messages.attachment}
                          onOpen={setViewer}
                        />

                        <div className="mt-1 flex items-center justify-end gap-1 text-[11px] opacity-70">
                          <span className="tabular">{clockTime(message.created_at, locale)}</span>
                          {message.is_mine && (
                            message.seen ? (
                              <CheckCheck size={14} aria-label={t.messages.seen} />
                            ) : (
                              <Check size={14} className="opacity-60" aria-label={t.messages.sent} />
                            )
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                )
              })}
              {typingHere && (
                <div className="flex justify-start">
                  <div className="rounded-2xl rounded-es-sm bg-[var(--surface)] px-3 py-2 text-xs text-accent">
                    {t.messages.typing}
                  </div>
                </div>
              )}
              <div ref={endRef} />
            </div>

            <form
              onSubmit={(event) => {
                event.preventDefault()
                submit()
              }}
              className="border-t border-[var(--border)] p-3"
            >
              {preparing && <p className="muted mb-2 text-sm">{t.messages.preparing}</p>}

              {pending && (
                <div className="mb-2 flex items-center gap-3 rounded-xl border border-[var(--border)] bg-[var(--surface)] p-2">
                  {pending.url && pending.kind !== 'audio' && (
                    <img src={pending.url} alt="" className="h-14 w-14 rounded-lg object-cover" />
                  )}
                  {pending.kind === 'audio' && pending.url ? (
                    <audio src={pending.url} controls className="h-10 min-w-0 flex-1" />
                  ) : (
                    <span className="min-w-0 flex-1 truncate text-sm">{pending.file.name}</span>
                  )}
                  <button
                    type="button"
                    onClick={clearPending}
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-lg hover:bg-[var(--card)]"
                    aria-label={t.messages.remove}
                  >
                    <X size={16} />
                  </button>
                </div>
              )}

              {recording ? (
                <div className="flex items-center gap-3">
                  <span className="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-[var(--danger-solid)]" />
                  <span className="tabular text-sm">{recordingClock(elapsed)}</span>
                  <span className="min-w-0 flex-1 truncate text-sm">{t.messages.recording}</span>
                  <Button type="button" variant="ghost" size="sm" onClick={() => stopRef.current(true)}>
                    {t.common.cancel}
                  </Button>
                  <Button type="button" size="sm" onClick={() => stopRef.current(false)}>
                    {t.messages.stopRecording}
                  </Button>
                </div>
              ) : (
                <div className="flex items-end gap-2">
                  <button
                    type="button"
                    onClick={() => void startRecording()}
                    disabled={send.isPending}
                    className="grid h-10 w-10 shrink-0 place-items-center rounded-lg hover:bg-[var(--surface)] disabled:opacity-50"
                    title={t.messages.record}
                    aria-label={t.messages.record}
                  >
                    <Mic size={17} />
                  </button>

                  <div className="relative">
                    <button
                      type="button"
                      onClick={() => setMenuOpen((open) => !open)}
                      disabled={send.isPending}
                      className="grid h-10 w-10 shrink-0 place-items-center rounded-lg hover:bg-[var(--surface)] disabled:opacity-50"
                      title={t.messages.attach}
                      aria-label={t.messages.attach}
                      aria-expanded={menuOpen}
                    >
                      <Paperclip size={17} />
                    </button>

                    {menuOpen && (
                      <>
                        <button
                          type="button"
                          className="fixed inset-0 z-10 cursor-default"
                          aria-label={t.common.cancel}
                          onClick={() => setMenuOpen(false)}
                        />
                        <div className="absolute bottom-12 start-0 z-20 w-44 overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-lg">
                          <button type="button" onClick={() => choose('image')} className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--surface)]">
                            <ImageIcon size={15} />
                            {t.messages.image}
                          </button>
                          <button type="button" onClick={() => choose('video')} className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--surface)]">
                            <Video size={15} />
                            {t.messages.video}
                          </button>
                          <button type="button" onClick={() => choose('pdf')} className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--surface)]">
                            <FileText size={15} />
                            {t.messages.pdf}
                          </button>
                          <button type="button" onClick={() => choose('file')} className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--surface)]">
                            <Paperclip size={15} />
                            {t.messages.file}
                          </button>
                        </div>
                      </>
                    )}
                  </div>

                  <textarea
                    value={draft}
                    onChange={(event) => onDraftChange(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault()
                        submit()
                      }
                    }}
                    placeholder={t.messages.placeholder}
                    rows={1}
                    className="max-h-32 min-w-0 flex-1 resize-none rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-2.5 text-sm outline-none focus:border-brand-500"
                  />

                  <Button
                    type="submit"
                    disabled={send.isPending || preparing || (!draft.trim() && !pending)}
                    className="shrink-0"
                    aria-label={t.common.send}
                  >
                    <Send size={16} />
                  </Button>
                </div>
              )}

              <input ref={fileRef} type="file" className="hidden" onChange={onPicked} />
            </form>

            {error && <p className="px-4 pb-3 text-sm text-danger">{error}</p>}
          </Card>
        )}
      </div>

      {viewer && <MediaViewer view={viewer} closeLabel={t.messages.closeViewer} onClose={() => setViewer(null)} />}
    </div>
  )
}
