<div>
    <form class="flex flex-col" wire:submit="login">
        <label for="username">Email Address</label>
        <input
            class="border! border-gray-500 p-3.5"
            id="username" 
            type="email" 
            wire:model="username"
        />

        <label for="password">Password</label>
        <input
            class="border! border-gray-500 p-3.5"
            id="password" 
            type="password" 
            wire:model="password"
        />

        <button class="border! border-gray-500 p-3.5 mt-3.5" type="submit">Login</button>
    </form>
</div>