import { Controller } from "@hotwired/stimulus"

/**
 * Active le tooltip Bootstrap sur l'élément porteur (lit son attribut title).
 * Le dispose() au disconnect évite les tooltips orphelins entre deux
 * navigations Turbo.
 */
export default class extends Controller {
    connect() {
        this.tooltip = new bootstrap.Tooltip(this.element)
    }

    disconnect() {
        this.tooltip?.dispose()
    }
}
