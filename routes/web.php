<?php

use Illuminate\Support\Facades\Route;
use Odden\Livewire\Screens\LivewireScreens;

Route::redirect('/', '/admin');

LivewireScreens::routes('crm', ['auth']);
