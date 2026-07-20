<?php return array (
  'info_barberia' => 
  array (
    'nombre' => 'Salon Render Test',
    'slug' => 'dlocal-render-4a3306',
  ),
  'dlocal' => 
  array (
    'api_key' => 'k',
    'secret_key' => 's',
    'sandbox' => true,
  ),
  'planes_cliente' => 
  array (
    'plan_1' => 
    array (
      'id' => 'plan_1',
      'name' => 'Basico',
      'description' => 'Plan basico',
      'currency' => 'UYU',
      'amount' => 1000,
      'frequency_type' => 'MONTHLY',
      'free_trial_days' => 7,
      'active' => true,
    ),
    'plan_2' => 
    array (
      'id' => 'plan_2',
      'name' => 'Premium',
      'description' => 'Plan premium',
      'currency' => 'UYU',
      'amount' => 2500,
      'frequency_type' => 'YEARLY',
      'free_trial_days' => 0,
      'active' => true,
    ),
    'plan_inactivo' => 
    array (
      'id' => 'plan_inactivo',
      'name' => 'Inactivo',
      'description' => 'No debe mostrarse',
      'currency' => 'UYU',
      'amount' => 999,
      'frequency_type' => 'MONTHLY',
      'free_trial_days' => 0,
      'active' => false,
    ),
  ),
);