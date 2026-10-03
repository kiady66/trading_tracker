import { Controller } from "@hotwired/stimulus"
import Quill from "quill"
import "quill/dist/quill.snow.css"

// Couleurs de surlignage : classes ql-bg-* fournies par le thème snow, pour
// stocker un HTML sans style inline (le HtmlSanitizer n'accepte que class).
const HIGHLIGHTS = ["yellow", "green", "blue", "red", "orange", "purple"]

let formatsRegistered = false

function registerFormats() {
    if (formatsRegistered) return
    formatsRegistered = true

    const Parchment = Quill.import("parchment")
    const Background = new Parchment.ClassAttributor("background", "ql-bg", {
        scope: Parchment.Scope.INLINE,
        whitelist: HIGHLIGHTS,
    })
    Quill.register(Background, true)
}

/*
 * Carte de notes d'une devise (page /biais) : éditeur Quill, bouton
 * Enregistrer, date de dernière sauvegarde.
 */
export default class extends Controller {
    static targets = ["editor", "saveButton", "status"]
    static values = { url: String, csrf: String }

    connect() {
        registerFormats()

        this.quill = new Quill(this.editorTarget, {
            theme: "snow",
            placeholder: "Notes fondamentales sur la devise…",
            modules: {
                toolbar: [
                    ["bold", "italic", "underline", "strike"],
                    [{ background: HIGHLIGHTS }],
                    [{ header: [2, 3, false] }],
                    [{ list: "ordered" }, { list: "bullet" }],
                    ["link", "clean"],
                ],
            },
        })

        this.quill.on("text-change", (delta, oldDelta, source) => {
            if (source === "user") this.setStatus("Modifications non enregistrées", "")
        })
    }

    disconnect() {
        this.quill = null
    }

    async save() {
        if (this.saving) return
        this.saving = true
        this.saveButtonTarget.disabled = true
        this.setStatus("Enregistrement…", "")

        const note = this.quill.getText().trim() === "" ? "" : this.quill.root.innerHTML

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ note, _token: this.csrfValue }),
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) {
                throw new Error(data.error || "Erreur d'enregistrement, réessaie.")
            }
            const date = new Date(data.updatedAt)
            this.setStatus(
                `Note du ${date.toLocaleDateString("fr-FR")} à ${date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })} ✓`,
                "success",
            )
        } catch (error) {
            this.setStatus(error.message, "error")
        } finally {
            this.saving = false
            this.saveButtonTarget.disabled = false
        }
    }

    setStatus(message, kind) {
        this.statusTarget.textContent = message
        this.statusTarget.className = `bias-meta ${kind}`
    }
}
