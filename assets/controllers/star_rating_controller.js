import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    static targets = ["star", "input"]

    connect() {
        const currentValue = parseInt(this.inputTarget.value) || 0
        this.updateStars(currentValue)
    }

    hover(event) {
        const value = parseInt(event.currentTarget.dataset.value)
        this.highlightStars(value)
    }

    reset() {
        const currentValue = parseInt(this.inputTarget.value) || 0
        this.updateStars(currentValue)
    }

    select(event) {
        const value = parseInt(event.currentTarget.dataset.value)
        const currentValue = parseInt(this.inputTarget.value) || 0

        if (currentValue === value) {
            this.inputTarget.value = ""
            this.updateStars(0)
        } else {
            this.inputTarget.value = value
            this.updateStars(value)
        }
    }

    highlightStars(upTo) {
        this.starTargets.forEach(star => {
            const starValue = parseInt(star.dataset.value)
            if (starValue <= upTo) {
                star.classList.remove("bi-star")
                star.classList.add("bi-star-fill", "text-warning")
            } else {
                star.classList.remove("bi-star-fill", "text-warning")
                star.classList.add("bi-star", "text-secondary")
            }
        })
    }

    updateStars(value) {
        this.highlightStars(value)
    }
}