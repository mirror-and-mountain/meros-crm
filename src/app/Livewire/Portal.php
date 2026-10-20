<?php

namespace MM\Meros\Crm\App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Portal extends Component {
    public string $username = '';
    public function mount() {
        $this->username = Auth::getUser()->name;
    }

    public function render() {
        return view('meros_crm::portal')
            ->layout('meros_crm::portal-layout');
    }

    public function logout() {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        $this->redirectRoute('portal.login');
    }
}