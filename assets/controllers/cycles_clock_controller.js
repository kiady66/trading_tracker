import { Controller } from "@hotwired/stimulus"

const CX = 310, CY = 310, R = 210
const NS = "http://www.w3.org/2000/svg"

const ZONES = [
    { from: 315, to: 45,  key: "plateau_haut", label: "plateau haut", glyph: "→", kind: "flat" },
    { from: 45,  to: 135, key: "baisse",       label: "baisse",       glyph: "↘", kind: "down" },
    { from: 135, to: 225, key: "plateau_bas",  label: "plateau bas",  glyph: "→", kind: "flat" },
    { from: 225, to: 315, key: "hausse",       label: "hausse",       glyph: "↗", kind: "up" },
]

const norm = (a) => ((a % 360) + 360) % 360

export default class extends Controller {
    static targets = ["dial", "ledger", "status", "saveButton", "refreshButton", "refreshInfo"]
    static values = { banks: Array, saveUrl: String, refreshUrl: String, refreshStatusUrl: String, refreshRunning: Boolean, csrf: String }

    connect() {
        // Base vide : la page affiche un message à la place du cadran
        if (!this.hasDialTarget) return

        // Un rafraîchissement lancé avant l'arrivée sur la page : on reprend le sondage
        if (this.refreshRunningValue) this.pollRefresh()

        this.banks = structuredClone(this.banksValue)
        this.drag = null
        this.saving = false

        this.onPointerDown = (e) => this.pointerDown(e)
        this.onPointerMove = (e) => this.pointerMove(e)
        this.onPointerEnd = () => this.endDrag()
        this.onKeyDown = (e) => this.keyDown(e)

        const svg = this.dialTarget
        svg.addEventListener("pointerdown", this.onPointerDown)
        svg.addEventListener("pointermove", this.onPointerMove)
        svg.addEventListener("pointerup", this.onPointerEnd)
        svg.addEventListener("pointercancel", this.onPointerEnd)
        svg.addEventListener("keydown", this.onKeyDown)

        this.drawStatic()
        this.render()
    }

    disconnect() {
        clearTimeout(this.pollTimer)
        if (!this.hasDialTarget) return

        const svg = this.dialTarget
        svg.removeEventListener("pointerdown", this.onPointerDown)
        svg.removeEventListener("pointermove", this.onPointerMove)
        svg.removeEventListener("pointerup", this.onPointerEnd)
        svg.removeEventListener("pointercancel", this.onPointerEnd)
        svg.removeEventListener("keydown", this.onKeyDown)
        svg.innerHTML = ""
    }

    // ── Géométrie ──────────────────────────────────────────

    px(a) { return CX + R * Math.sin(a * Math.PI / 180) }
    py(a) { return CY - R * Math.cos(a * Math.PI / 180) }

    zoneOf(angle) {
        const a = norm(angle)
        return ZONES.find(z => z.from > z.to ? (a >= z.from || a < z.to) : (a >= z.from && a < z.to))
    }

    el(tag, attrs = {}) {
        const n = document.createElementNS(NS, tag)
        for (const k in attrs) n.setAttribute(k, attrs[k])
        return n
    }

    // ── Rendu ──────────────────────────────────────────────

    drawStatic() {
        const svg = this.dialTarget
        svg.append(this.el("circle", { cx: CX, cy: CY, r: R, class: "ring" }))
        for (const a of [45, 135, 225, 315]) {
            svg.append(this.el("line", {
                x1: CX + (R - 14) * Math.sin(a * Math.PI / 180), y1: CY - (R - 14) * Math.cos(a * Math.PI / 180),
                x2: CX + (R + 14) * Math.sin(a * Math.PI / 180), y2: CY - (R + 14) * Math.cos(a * Math.PI / 180),
                class: "spoke",
            }))
        }
        const zpos = { plateau_haut: [CX, CY - 118], baisse: [CX + 124, CY + 5], plateau_bas: [CX, CY + 126], hausse: [CX - 124, CY + 5] }
        for (const z of ZONES) {
            const [x, y] = zpos[z.key]
            const t = this.el("text", { x, y, class: "zone", "text-anchor": "middle" })
            t.textContent = z.label
            this.dialTarget.append(t)
        }
    }

    render() {
        const svg = this.dialTarget
        svg.querySelectorAll(".pill").forEach(n => n.remove())

        this.banks.forEach((b, i) => {
            const z = this.zoneOf(b.angle)
            const w = b.code.length > 3 ? 56 : 48
            const x = this.px(b.angle), y = this.py(b.angle)

            const g = this.el("g", {
                class: `pill ${z.kind}${b.retracement ? " retrac" : ""}`,
                tabindex: "0", role: "slider",
                "aria-label": `${b.code}, ${z.label}`,
                "aria-valuenow": Math.round(b.angle), "aria-valuemin": "0", "aria-valuemax": "359",
                "data-i": i,
            })
            g.append(this.el("rect", { x: x - w / 2, y: y - 14, width: w, height: 28, rx: 14 }))
            const t = this.el("text", { x, y, "text-anchor": "middle", "dominant-baseline": "central" })
            t.textContent = b.code
            g.append(t)
            svg.append(g)
        })

        this.renderLedger()
    }

    renderLedger() {
        const tb = this.ledgerTarget
        tb.innerHTML = ""
        this.banks.forEach((b, i) => {
            const z = this.zoneOf(b.angle)
            const tr = document.createElement("tr")
            tr.innerHTML = `
                <td><span class="tick"></span></td>
                <td><input type="text" data-i="${i}" data-f="rate" maxlength="30" aria-label="Taux ${b.code}"></td>
                <td class="ph">${z.label}</td>
                <td><select data-i="${i}" data-f="bias" aria-label="Biais ${b.code}">
                    <option value="neutre">neutre</option>
                    <option value="hawkish">hawkish</option>
                    <option value="dovish">dovish</option>
                </select></td>
                <td><input type="checkbox" data-i="${i}" data-f="retracement" ${b.retracement ? "checked" : ""} aria-label="Retracement ${b.code}"></td>`
            tr.querySelector(".tick").textContent = b.code
            tr.querySelector("[data-f=rate]").value = b.rate
            tr.querySelector("[data-f=bias]").value = b.bias
            tb.append(tr)
        })
    }

    // ── Édition du tableau (data-action="input->cycles-clock#ledgerInput") ──

    ledgerInput(event) {
        const t = event.target, i = t.dataset.i, f = t.dataset.f
        if (i === undefined) return
        this.banks[i][f] = t.type === "checkbox" ? t.checked : t.value
        // Ne pas re-rendre le ledger pendant la frappe dans le champ taux
        if (f === "rate") return
        const focused = document.activeElement === t ? { i, f } : null
        this.render()
        if (focused) this.ledgerTarget.querySelector(`[data-i="${focused.i}"][data-f="${focused.f}"]`)?.focus()
    }

    // ── Drag sur le cadran ─────────────────────────────────

    svgPoint(evt) {
        const svg = this.dialTarget
        const pt = svg.createSVGPoint()
        pt.x = evt.clientX
        pt.y = evt.clientY
        return pt.matrixTransform(svg.getScreenCTM().inverse())
    }

    pointerDown(e) {
        const g = e.target.closest(".pill")
        if (!g) return
        this.drag = +g.dataset.i
        g.classList.add("dragging")
        g.setPointerCapture(e.pointerId)
        e.preventDefault()
    }

    pointerMove(e) {
        if (this.drag === null) return
        const p = this.svgPoint(e)
        let a = norm(Math.atan2(p.x - CX, CY - p.y) * 180 / Math.PI)
        if (e.shiftKey) a = Math.round(a / 5) * 5
        this.banks[this.drag].angle = a
        this.render()
        this.dialTarget.querySelector(`.pill[data-i="${this.drag}"]`)?.classList.add("dragging")
    }

    endDrag() {
        if (this.drag === null) return
        this.dialTarget.querySelectorAll(".dragging").forEach(n => n.classList.remove("dragging"))
        this.drag = null
    }

    keyDown(e) {
        const g = e.target.closest(".pill")
        if (!g) return
        const step = e.shiftKey ? 10 : 2
        const i = +g.dataset.i
        if (e.key === "ArrowRight" || e.key === "ArrowUp") this.banks[i].angle = norm(this.banks[i].angle + step)
        else if (e.key === "ArrowLeft" || e.key === "ArrowDown") this.banks[i].angle = norm(this.banks[i].angle - step)
        else return
        e.preventDefault()
        this.render()
        this.dialTarget.querySelector(`.pill[data-i="${i}"]`)?.focus()
    }

    // ── Enregistrement ─────────────────────────────────────

    async save() {
        if (this.saving) return
        this.saving = true
        this.saveButtonTarget.disabled = true
        this.setStatus("Enregistrement…", "muted")

        try {
            const response = await fetch(this.saveUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ banks: this.banks, _token: this.csrfValue }),
            })
            if (!response.ok) {
                throw new Error((await response.json()).error || "Erreur d'enregistrement")
            }
            this.setStatus("Enregistré ✓", "success")
        } catch (error) {
            this.setStatus(error.message || "Impossible d'enregistrer, réessaie.", "error")
        } finally {
            this.saving = false
            this.saveButtonTarget.disabled = false
        }
    }

    setStatus(message, kind) {
        this.statusTarget.textContent = message
        this.statusTarget.className = "cycles-status " + kind
    }

    // ── Actualisation des taux par l'IA (commande en arrière-plan + sondage) ──

    async refresh() {
        this.setRefreshLoading(true)
        this.setRefreshInfo("Lancement…")

        try {
            const response = await fetch(this.refreshUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ _token: this.csrfValue }),
            })
            // 409 : déjà en cours (autre onglet) → on se contente de suivre l'exécution
            if (!response.ok && response.status !== 409) {
                throw new Error((await response.json()).error || "Erreur au lancement")
            }
            this.pollRefresh()
        } catch (error) {
            this.setRefreshLoading(false)
            this.setRefreshInfo(error.message || "Impossible de lancer l'actualisation.", true)
        }
    }

    // Sonde l'état en base toutes les minutes (une exécution dure ~3 min)
    pollRefresh() {
        this.setRefreshLoading(true)
        this.setRefreshInfo("Actualisation en cours… (environ 3 minutes)")

        const tick = async () => {
            try {
                const status = await (await fetch(this.refreshStatusUrlValue, { headers: { Accept: "application/json" } })).json()
                if (status.status === "running") {
                    this.pollTimer = setTimeout(tick, 60000)
                    return
                }
                this.refreshFinished(status)
            } catch {
                this.pollTimer = setTimeout(tick, 60000)
            }
        }
        this.pollTimer = setTimeout(tick, 60000)
    }

    refreshFinished(status) {
        this.setRefreshLoading(false)

        if (status.status === "error") {
            this.setRefreshInfo(`Échec : ${status.message || "erreur inconnue"}`, true)
            return
        }

        // Nouvelles valeurs (taux, biais) sans recharger la page ; les angles
        // n'ont pas bougé côté serveur mais on repart de l'état en base
        if (Array.isArray(status.banks)) {
            this.banks = structuredClone(status.banks)
            this.render()
        }

        const when = status.finishedAt ? new Date(status.finishedAt).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" }) : ""
        const info = this.refreshInfoTarget
        info.classList.remove("error")
        info.textContent = `Dernière actualisation le ${when}${status.changes?.length ? " :" : " — aucun changement."}`
        if (status.changes?.length) {
            const list = document.createElement("ul")
            for (const change of status.changes) {
                const li = document.createElement("li")
                li.textContent = change
                list.append(li)
            }
            info.append(list)
        }
    }

    setRefreshLoading(loading) {
        const button = this.refreshButtonTarget
        button.disabled = loading
        button.setAttribute("aria-busy", loading ? "true" : "false")
        button.querySelector("i")?.classList.toggle("spin", loading)
        button.querySelector("span").textContent = loading ? "Actualisation en cours…" : "Actualiser les taux par l'IA"
    }

    setRefreshInfo(message, isError = false) {
        this.refreshInfoTarget.textContent = message
        this.refreshInfoTarget.classList.toggle("error", isError)
    }
}
