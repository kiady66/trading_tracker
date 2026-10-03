import { Controller } from "@hotwired/stimulus"
import Sortable from "sortablejs"

/*
 * Page /biais : changement de biais d'une devise (select de chaque carte) et
 * liste des paires hawkish/dovish ordonnable par glisser-déposer. La liste est
 * toujours celle renvoyée par le serveur, jamais recalculée côté client.
 */
export default class extends Controller {
    static targets = ["pairs", "pairsCount", "pairsStatus"]
    static values = { orderUrl: String, csrf: String }

    connect() {
        this.sortable = Sortable.create(this.pairsTarget, {
            animation: 150,
            draggable: ".bias-pair",
            onEnd: () => this.saveOrder(),
        })
    }

    disconnect() {
        this.sortable?.destroy()
        this.sortable = null
    }

    async changeBias(event) {
        const select = event.currentTarget
        const card = select.closest(".bias-card")
        select.disabled = true

        try {
            const data = await this.post(select.dataset.url, { bias: select.value })
            card.classList.remove("bias-hawkish", "bias-dovish", "bias-neutre")
            card.classList.add(`bias-${data.bias}`)
            const updated = card.querySelector("[data-bias-updated]")
            if (updated) updated.textContent = this.formatDate(data.updatedAt)
            this.renderPairs(data.pairs)
            this.setPairsStatus("Liste mise à jour.", "success")
        } catch (error) {
            this.setPairsStatus(error.message, "error")
        } finally {
            select.disabled = false
        }
    }

    async saveOrder() {
        const pairs = [...this.pairsTarget.querySelectorAll(".bias-pair")].map((li) => li.dataset.pair)

        try {
            const data = await this.post(this.orderUrlValue, { pairs })
            this.renderPairs(data.pairs)
            this.setPairsStatus("Ordre enregistré ✓", "success")
        } catch (error) {
            // 409 : les paires ont changé entre-temps, le serveur renvoie la liste à jour
            if (error.pairs) this.renderPairs(error.pairs)
            this.setPairsStatus(error.message, "error")
        }
    }

    renderPairs(pairs) {
        const list = this.pairsTarget
        list.replaceChildren()

        if (pairs.length === 0) {
            const empty = document.createElement("li")
            empty.className = "bias-pairs-empty"
            empty.textContent = "Aucune paire : il faut au moins une devise hawkish et une dovish."
            list.append(empty)
        }

        for (const p of pairs) {
            const li = document.createElement("li")
            li.className = "bias-pair"
            li.dataset.pair = p.pair

            const grip = document.createElement("i")
            grip.className = "bi bi-grip-vertical bias-grip"

            const name = document.createElement("span")
            name.className = "bias-pair-name"
            name.textContent = p.pair

            const biases = document.createElement("span")
            biases.className = "bias-pair-biases"
            biases.append(this.tag(p.base, p.baseBias), this.tag(p.quote, p.quoteBias))

            li.append(grip, name, biases)
            list.append(li)
        }

        if (this.hasPairsCountTarget) this.pairsCountTarget.textContent = `(${pairs.length})`
    }

    tag(currency, bias) {
        const span = document.createElement("span")
        span.className = `bias-tag ${bias}`
        span.textContent = `${currency} ${bias}`
        return span
    }

    async post(url, body) {
        const response = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ ...body, _token: this.csrfValue }),
        })
        const data = await response.json().catch(() => ({}))
        if (!response.ok) {
            const error = new Error(data.error || "Erreur d'enregistrement, réessaie.")
            error.pairs = data.pairs
            throw error
        }
        return data
    }

    setPairsStatus(message, kind) {
        this.pairsStatusTarget.textContent = message
        this.pairsStatusTarget.className = `bias-meta mt-2 mb-0 ${kind}`
    }

    formatDate(iso) {
        const date = new Date(iso)
        return `${date.toLocaleDateString("fr-FR")} à ${date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })}`
    }
}
