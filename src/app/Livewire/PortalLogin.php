<?php

namespace MM\Meros\Crm\App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class PortalLogin extends Component {
    public string $username = '';
    public string $password = '';

    public function render() {
        return view('meros_crm::portal-login')
            ->layout('meros_crm::portal-layout');
    }

    public function login() {
        if ($this->username !== '' && $this->password !== '') {
            $result = Auth::attempt([
                'email' => $this->username,
                'password' => $this->password
            ]);

            if ($result === true) {
                $this->redirectRoute('portal.dashboard');
            }
        }
    }
}