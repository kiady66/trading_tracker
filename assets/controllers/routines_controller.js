import { Controller } from "@hotwired/stimulus"

/*
 * Onglets, progression par carte et remise à zéro des checklists de routines.
 * Aucune persistance : les cases se réinitialisent quand la page est quittée.
 */
export default class extends Controller {
    static targets = ["tabButton", "tab", "card"]

    connect() {
        const fromHash = location.hash.slice(1)
        const initial = this.tabTargets.some(t => t.id === fromHash) ? fromHash : this.tabTargets[0]?.id
        if (initial) this.activate(initial)
        this.cardTargets.forEach(card => this.refresh(card))
    }

    // data-action="routines#showTab" data-routines-tab-param="wk"
    showTab(event) {
        this.activate(event.params.tab)
        history.replaceState(null, "", `#${event.params.tab}`)
        window.scrollTo({ top: 0, behavior: "smooth" })
    }

    // data-action="change->routines#cardChanged" posé sur la racine (délégation)
    cardChanged(event) {
        const card = event.target.closest("[data-routines-target~='card']")
        if (card) this.refresh(card)
    }

    // data-action="routines#resetTab" sur le bouton « Tout décocher » d'un onglet
    resetTab(event) {
        const tab = event.currentTarget.closest("[data-routines-target~='tab']")
        tab.querySelectorAll("input[type=checkbox]").forEach(box => { box.checked = false })
        tab.querySelectorAll("[data-routines-target~='card']").forEach(card => this.refresh(card))
    }

    activate(id) {
        this.tabButtonTargets.forEach(b => b.classList.toggle("on", b.dataset.routinesTabParam === id))
        this.tabTargets.forEach(t => t.classList.toggle("on", t.id === id))
    }

    refresh(card) {
        const boxes = card.querySelectorAll("input[type=checkbox]")
        const done = [...boxes].filter(b => b.checked).length
        const bar = card.querySelector(".rt-prog b")
        const count = card.querySelector(".rt-cnt")
        if (bar) bar.style.width = (boxes.length ? done / boxes.length * 100 : 0) + "%"
        if (count) count.textContent = `${done}/${boxes.length}`
        card.classList.toggle("complete", boxes.length > 0 && done === boxes.length)
    }
}
