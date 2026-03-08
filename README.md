# LaraMicrosoft-Auth

Paquete **library** en PHP 8.4 para autenticación social con **Microsoft Entra ID** (Office 365). La lógica de negocio está en el backend; el frontend (Vue, Nuxt o React) solo redirige al login de Microsoft y envía el código al backend.

## Requisitos

- PHP 8.4+
- Aplicación registrada en **Azure Portal** (Microsoft Entra ID)

## Instalación

```bash
composer require laramicrosoft/auth
```

## Registro de la aplicación en Azure

1. Entra en [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** (o **Azure Active Directory**) → **Registros de aplicaciones** → **Nuevo registro**.

2. Configura:
   - **Nombre**: por ejemplo `Mi App`.
   - **Tipos de cuenta admitidos**: según tu caso (una sola organización, varias, o cuentas personales).
   - **URI de redirección**: elige **Web** y pon la URL de callback de tu backend, por ejemplo:
     - `https://tu-dominio.com/auth/entra/callback`
     - En local: `http://localhost:8080/auth/entra/callback` (o el puerto que uses).

3. Después de crear, anota:
   - **Id. de aplicación (cliente)** → `client_id`
   - **Id. de directorio (inquilino)** → `tenant` (o usa `common` para multi-tenant)

4. En **Certificados y secretos**, crea un **Nuevo secreto de cliente** y copia su valor → `client_secret`.

5. En **Permisos de API** → **Agregar un permiso** → **Microsoft Graph** → **Permisos delegados**, añade por ejemplo:
   - `openid`
   - `profile`
   - `email`
   - `User.Read`

Guarda `client_id`, `client_secret`, `tenant` y la `redirect_uri` que hayas configurado; los usarás en el backend.

### Error AADSTS900144 ("The request body must contain the following parameter: 'client_id'")

Si ves este error al iniciar sesión, **el `client_id` no está llegando a Microsoft**: suele deberse a que no está configurado o está vacío en tu app.

- Asegúrate de tener en tu `.env` (o config) el **Id. de aplicación (cliente)** de Azure y de que la app lo lee al construir la config:
  - `ENTRA_CLIENT_ID` (o el nombre que uses) debe ser el valor exacto del "Application (client) ID" del registro en Azure.
- No uses comillas vacías ni valores por defecto vacíos. Si la variable de entorno no existe, la librería lanzará una excepción clara al crear la config.

## Uso en el backend (PHP)

### Configuración

```php
use LaraMicrosoft\Auth\Config\EntraIdConfig;
use LaraMicrosoft\Auth\EntraIdAuthService;

// Asegúrate de que ENTRA_CLIENT_ID, ENTRA_CLIENT_SECRET y redirect_uri están definidos y no vacíos
$config = EntraIdConfig::fromArray([
    'client_id'     => getenv('ENTRA_CLIENT_ID'),      // Id. de aplicación (cliente) de Azure
    'client_secret' => getenv('ENTRA_CLIENT_SECRET'),
    'redirect_uri'  => 'https://tu-dominio.com/auth/entra/callback',
    'tenant'        => getenv('ENTRA_TENANT_ID') ?: 'common',
    'scopes'        => ['openid', 'profile', 'email', 'User.Read'],
]);

$entraAuth = new EntraIdAuthService($config);
```

### Endpoints que debe exponer tu backend

Tu API/framework debe exponer al menos dos rutas que usen el servicio.

#### 1. Obtener URL de login (para que el frontend redirija)

Ejemplo genérico (adaptable a tu framework):

```php
// GET /auth/entra/url
$state = bin2hex(random_bytes(16));
// Guardar $state en sesión o en un almacén asociado al usuario (ej. Redis con TTL)
$_SESSION['entra_oauth_state'] = $state;

['url' => $url, 'state' => $state] = $entraAuth->getAuthorizationUrl($state);

// Respuesta JSON para el frontend
return ['url' => $url, 'state' => $state];
```

El frontend redirigirá al usuario a `url`.

#### 2. Callback (intercambio de código por token y usuario)

El usuario vuelve desde Microsoft a tu `redirect_uri` con query params `code` y `state`.  
Tu backend debe recibir **code** y **state** (ya sea porque el frontend los envía a tu API o porque el callback es una ruta de tu backend que lee la query).

```php
// POST /auth/entra/callback  (body: { "code": "...", "state": "..." })
// o GET /auth/entra/callback?code=...&state=...
$code  = $request->get('code') ?? $request->getInput('code');
$state = $request->get('state') ?? $request->getInput('state');
$expectedState = $_SESSION['entra_oauth_state'] ?? null; // el que guardaste al generar la URL

$result = $entraAuth->exchangeCodeAndGetUser($code, $state, $expectedState);

$accessToken = $result['token'];
$user        = $result['user'];

// $user->getId(), $user->getEmail(), $user->getName(), etc.
// Aquí creas o actualizas sesión y devuelves token/cookie al frontend
```

Manejo de excepciones:

```php
use LaraMicrosoft\Auth\Exception\InvalidStateException;
use LaraMicrosoft\Auth\Exception\TokenExchangeException;

try {
    $result = $entraAuth->exchangeCodeAndGetUser($code, $state, $expectedState);
} catch (InvalidStateException $e) {
    // Estado inválido (CSRF)
} catch (TokenExchangeException $e) {
    // Error al canjear el código
}
```

## Uso desde el frontend (Vue, Nuxt, React)

El flujo es siempre el mismo: obtener la URL del backend, redirigir al usuario, y cuando vuelva con `code` y `state`, enviarlos al backend.

### Vue 3 / React (ejemplo con fetch)

```javascript
// 1) Iniciar login: pedir URL al backend y redirigir
async function loginWithEntra() {
  const res = await fetch('/api/auth/entra/url', { credentials: 'include' });
  const { url, state } = await res.json();
  sessionStorage.setItem('entra_oauth_state', state);
  window.location.href = url;
}

// 2) En la página de callback (ruta a la que apunta redirect_uri)
//    Leer code y state de la URL y enviarlos al backend
const params = new URLSearchParams(window.location.search);
const code = params.get('code');
const state = params.get('state');
const savedState = sessionStorage.getItem('entra_oauth_state');

if (code && state) {
  const res = await fetch('/api/auth/entra/callback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ code, state }),
  });
  const data = await res.json();
  // Redirigir al inicio o dashboard (la sesión/cookie la establece el backend)
}
```

### Nuxt 3

Puedes tener una página que inicia el login y otra que es el callback (configurada como `redirect_uri` en Azure).

```vue
<!-- pages/login.vue -->
<script setup>
async function loginWithEntra() {
  const { data } = await useFetch('/api/auth/entra/url');
  if (data.value?.url) {
    sessionStorage.setItem('entra_oauth_state', data.value.state);
    await navigateTo(data.value.url, { external: true });
  }
}
</script>

<template>
  <button @click="loginWithEntra">Iniciar sesión con Office 365</button>
</template>
```

```vue
<!-- pages/auth/callback.vue (ruta que coincida con redirect_uri) -->
<script setup>
const route = useRoute();
const code = route.query.code;
const state = route.query.state;

if (code && state) {
  await $fetch('/api/auth/entra/callback', {
    method: 'POST',
    body: { code, state },
  });
  await navigateTo('/');
}
</script>
```

En Azure, el **URI de redirección** sería por ejemplo: `https://tu-dominio.com/auth/callback` (o la ruta que uses para esta página).

## API del servicio

| Método | Descripción |
|--------|-------------|
| `getAuthorizationUrl(?string $state)` | Devuelve `['url' => string, 'state' => string]` para redirigir al login de Entra ID. |
| `exchangeCodeForToken(string $code, string $state, ?string $expectedState)` | Canjea el código por un `AccessToken`. Lanza si el state no coincide o si falla el canje. |
| `getUser(AccessToken $token)` | Obtiene el recurso de usuario (EntraIdResourceOwner) desde Microsoft. |
| `exchangeCodeAndGetUser(string $code, string $state, ?string $expectedState)` | Canjea código y devuelve `['token' => AccessToken, 'user' => EntraIdResourceOwner]`. |

## Licencia

MIT.
