<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\FormsPage;

/**
 * Redirects to the Forms page after a successful local save and asks that
 * page to run one automatic push. The save itself never waits on the network.
 */
trait RedirectsWithFormSync
{
    protected function redirectAfterFormSave(string $form): void
    {
        session()->flash('forms.sync_after_save', true);

        $this->redirect(FormsPage::getUrl(['form' => $form]));
    }
}
