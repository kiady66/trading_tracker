import { Controller } from '@hotwired/stimulus';

/*
 * Grise les sous-toggles de visibilité tant que le toggle maître est désactivé,
 * et recoche tout quand on active le maître (« activer le maître partage tout »).
 * Le serveur reste la source de vérité : ceci est purement cosmétique.
 */
export default class extends Controller {
    static targets = ['master', 'sub', 'monthOnly'];

    connect() {
        this.refresh();
    }

    toggleMaster() {
        if (this.masterTarget.checked) {
            this.subTargets.forEach((sub) => { sub.checked = true; });
            if (this.hasMonthOnlyTarget) {
                this.monthOnlyTarget.checked = false;
            }
        }
        this.refresh();
    }

    refresh() {
        const enabled = this.masterTarget.checked;
        [...this.subTargets, ...(this.hasMonthOnlyTarget ? [this.monthOnlyTarget] : [])].forEach((sub) => {
            sub.disabled = !enabled;
            sub.closest('.form-check')?.classList.toggle('opacity-50', !enabled);
        });
    }
}
