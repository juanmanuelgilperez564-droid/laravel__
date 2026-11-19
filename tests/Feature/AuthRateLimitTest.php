<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear(mb_strtolower('test@example.com') . '|127.0.0.1');
        RateLimiter::clear(mb_strtolower('test@example.com') . '|127.0.0.1|bloqueo');
    }

    /** 1. Ejecutar la función de registro de fallos con una clave combinada */
    public function test_registro_fallos_con_clave_combinada()
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $clave = mb_strtolower($email) . '|' . $ip;

        RateLimiter::hit($clave, 60);

        $this->assertEquals(1, RateLimiter::attempts($clave));
    }

    /** 2. Invocar la función de verificación de bloqueo pasándole un valor igual a 5 y mayor a 5 */
    public function test_verificacion_bloqueo_igual_y_mayor_a_cinco()
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $clave = mb_strtolower($email) . '|' . $ip;

        for ($i = 0; $i < 4; $i++) {
            RateLimiter::hit($clave, 60);
        }

        $this->assertEquals(4, RateLimiter::attempts($clave));
        $this->assertFalse(RateLimiter::tooManyAttempts($clave, 5));

        RateLimiter::hit($clave, 60);

        $this->assertEquals(5, RateLimiter::attempts($clave));
        $this->assertTrue(RateLimiter::tooManyAttempts($clave, 5));
    }

    /** 3. Simular que han transcurrido 15 minutos y 1 segundo desde el primer fallo */
    public function test_simulacion_tiempo_transcurrido_tras_bloqueo()
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $claveBloqueo = mb_strtolower($email) . '|' . $ip . '|bloqueo';

        RateLimiter::hit($claveBloqueo, 60);
        $this->assertTrue(RateLimiter::tooManyAttempts($claveBloqueo, 1));

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->assertFalse(RateLimiter::tooManyAttempts($claveBloqueo, 1));
    }

    /** 4. Ejecutar el método encargado de reiniciar el contador tras una autenticación exitosa */
    public function test_reinicio_contador_tras_autenticacion_exitosa()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('Password123*'),
        ]);

        $ip = '127.0.0.1';
        $clave = mb_strtolower('test@example.com') . '|' . $ip;

        RateLimiter::hit($clave, 60);
        $this->assertEquals(1, RateLimiter::attempts($clave));

        // Forzamos la IP a 127.0.0.1 para que coincida con la clave evaluada
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', [
            'email' => 'test@example.com',
            'password' => 'Password123*',
        ]);

        $response->assertRedirect('/planificador');
        $this->assertEquals(0, RateLimiter::attempts($clave));
    }

    /** 5. Probar la función encargada de construir la respuesta de error cuando el estado es "bloqueado" */
    public function test_construccion_respuesta_error_cuando_esta_bloqueado()
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $claveBloqueo = mb_strtolower($email) . '|' . $ip . '|bloqueo';

        RateLimiter::hit($claveBloqueo, 60);

        // Forzamos la IP a 127.0.0.1 para que el controlador detecte el bloqueo activo
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
    }
}