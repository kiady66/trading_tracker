import { Controller } from "@hotwired/stimulus"

const POLL_INTERVAL = 15000
const STORAGE_KEY = "chat.open"

export default class extends Controller {
    static targets = ["bar", "badge", "window", "messages", "input", "welcome", "error"]
    static values = { url: String }

    connect() {
        this.csrfToken = null
        this.pollTimer = null
        this.sending = false

        // data-turbo-permanent : le widget survit aux navigations Turbo, on
        // rafraîchit donc le badge à chaque chargement de page (sans polling)
        this.onTurboLoad = () => { if (!this.isOpen()) this.refreshBadge() }
        document.addEventListener("turbo:load", this.onTurboLoad)

        if (localStorage.getItem(STORAGE_KEY) === "1") {
            this.showWindow()
            this.loadMessages(true)
            this.startPolling()
        } else {
            this.refreshBadge()
        }
    }

    disconnect() {
        this.stopPolling()
        document.removeEventListener("turbo:load", this.onTurboLoad)
    }

    // ── Ouverture / réduction ──────────────────────────────

    isOpen() {
        return !this.windowTarget.classList.contains("d-none")
    }

    open() {
        localStorage.setItem(STORAGE_KEY, "1")
        this.showWindow()
        this.loadMessages(true)
        this.startPolling()
    }

    close() {
        localStorage.setItem(STORAGE_KEY, "0")
        this.windowTarget.classList.add("d-none")
        this.barTarget.classList.remove("d-none")
        this.stopPolling()
    }

    showWindow() {
        this.windowTarget.classList.remove("d-none")
        this.barTarget.classList.add("d-none")
        this.inputTarget.focus()
    }

    // ── Polling ────────────────────────────────────────────

    startPolling() {
        this.stopPolling()
        this.pollTimer = setInterval(() => this.loadMessages(false), POLL_INTERVAL)
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer)
            this.pollTimer = null
        }
    }

    // ── Chargement ─────────────────────────────────────────

    async refreshBadge() {
        try {
            const response = await fetch(this.urlValue + "?unread_only=1", {
                headers: { Accept: "application/json" },
            })
            if (!response.ok) return
            const data = await response.json()
            this.updateBadge(data.unreadCount)
        } catch {
            // silencieux : le badge sera retenté au prochain chargement
        }
    }

    async loadMessages(scrollToBottom) {
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: "application/json" },
            })
            if (!response.ok) return
            const data = await response.json()
            this.csrfToken = data.csrfToken
            this.renderMessages(data.messages, scrollToBottom)
            this.updateBadge(0)
        } catch {
            // silencieux : retenté au prochain polling
        }
    }

    renderMessages(messages, forceScroll) {
        const container = this.messagesTarget
        const atBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 30

        container.innerHTML = ""
        if (messages.length === 0) {
            container.appendChild(this.welcomeTarget.content.cloneNode(true))
            return
        }

        for (const message of messages) {
            container.appendChild(this.buildMessage(message))
        }

        if (forceScroll || atBottom) {
            container.scrollTop = container.scrollHeight
        }
    }

    buildMessage({ fromAdmin, content, createdAt }) {
        const wrapper = document.createElement("div")
        wrapper.className = "chat-msg " + (fromAdmin ? "from-admin" : "from-user")

        if (fromAdmin) {
            const author = document.createElement("div")
            author.className = "chat-author"
            author.textContent = "Support"
            wrapper.appendChild(author)
        }

        const bubble = document.createElement("div")
        bubble.className = "chat-bubble"
        bubble.textContent = content
        wrapper.appendChild(bubble)

        const time = document.createElement("div")
        time.className = "chat-time"
        time.textContent = createdAt
        wrapper.appendChild(time)

        return wrapper
    }

    updateBadge(count) {
        this.badgeTarget.textContent = count
        this.badgeTarget.classList.toggle("d-none", count === 0)
    }

    // ── Envoi ──────────────────────────────────────────────

    keydown(event) {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault()
            event.target.form.requestSubmit()
        }
    }

    autogrow() {
        const input = this.inputTarget
        input.style.height = "auto"
        input.style.height = input.scrollHeight + "px"
    }

    async send(event) {
        event.preventDefault()
        const content = this.inputTarget.value.trim()
        if (content === "" || this.sending) return

        this.hideError()
        this.sending = true

        // Affichage optimiste : la bulle apparaît tout de suite
        const now = new Date()
        const pad = (n) => String(n).padStart(2, "0")
        const optimistic = this.buildMessage({
            fromAdmin: false,
            content: content,
            createdAt: `${pad(now.getDate())}/${pad(now.getMonth() + 1)} ${pad(now.getHours())}:${pad(now.getMinutes())}`,
        })
        this.messagesTarget.appendChild(optimistic)
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight
        this.inputTarget.value = ""
        this.autogrow()

        try {
            if (this.csrfToken === null) {
                await this.loadMessages(true)
            }
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ content: content, _token: this.csrfToken }),
            })
            if (!response.ok) {
                throw new Error((await response.json()).error || "Erreur d'envoi")
            }
        } catch (error) {
            optimistic.remove()
            this.inputTarget.value = content
            this.showError(error.message || "Impossible d'envoyer le message, réessaie.")
        } finally {
            this.sending = false
        }
    }

    showError(message) {
        this.errorTarget.textContent = message
        this.errorTarget.classList.remove("d-none")
    }

    hideError() {
        this.errorTarget.textContent = ""
        this.errorTarget.classList.add("d-none")
    }
}
