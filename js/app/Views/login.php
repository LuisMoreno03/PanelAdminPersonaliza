<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - PersonalizaRegalo</title>
    

    <style>
        body {
            background: linear-gradient(135deg, #111827, #1f2937);
        }
        .logo-login{
            width: 50px;
            height: 50px;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">

    <div class="bg-white/10 backdrop-blur-xl shadow-2xl rounded-2xl p-10 w-full max-w-md border border-white/20">
        
        <h2 class="text-3xl flex items-center font-bold text-white text-center mb-8">
            Bienvenido <img class="logo-login" src="https://cdn.shopify.com/s/files/1/0825/8315/9125/files/upcart_header_logo_1757515655029.png" alt="">
        </h2>

        <?php if(session()->getFlashdata('error')): ?>
            <div class="bg-red-500/20 text-red-300 px-4 py-3 rounded-lg mb-4">
                <?= session()->getFlashdata('error') ?>
            </div>
        <?php endif; ?>

        <form action="https://paneladministrativopersonaliza.com/auth/login" method="POST" class="space-y-5">

            <!-- Email -->
            <div>
                <label class="text-gray-200 font-semibold">Correo electrónico</label>
                <input 
                    type="email" 
                    name="email" 
                    required 
                    class="w-full mt-2 px-4 py-3 rounded-xl bg-gray-800/50 text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="ejemplo@correo.com">
            </div>

            <!-- Password -->
            <div>
                <label class="text-gray-200 font-semibold">Contraseña</label>
                <input 
                    type="password" 
                    name="password" 
                    required 
                    class="w-full mt-2 px-4 py-3 rounded-xl bg-gray-800/50 text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="••••••••">
            </div>

            <!-- Login button -->
            <button 
                type="submit" 
                class="w-full bg-blue-600 hover:bg-blue-700 transition text-white py-3 rounded-xl font-semibold text-lg shadow-lg">
                Iniciar sesión
            </button>

        </form>

        <p class="text-center text-gray-300 mt-6 text-sm">
            © <?= date('Y'); ?> Panel Administrativo – Todos los derechos reservados
        </p>

    </div>
<script src="https://cdn.tailwindcss.com"></script>
</body>
</html>
