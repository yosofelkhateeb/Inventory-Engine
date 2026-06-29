<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
 email: '',
 password: '',
 remember: false,
});

function submit() {
 form.post('/login', {
 onFinish: () => form.reset('password'),
 });
}
</script>

<template>
 <div class="min-h-screen bg-gray-50 dark:bg-slate-800 flex items-center justify-center">
 <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border p-8 w-full max-w-sm">
 <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100 mb-1">Inventory Engine</h1>
 <p class="text-sm text-gray-500 dark:text-slate-400 mb-6">Sign in to your account</p>

 <form @submit.prevent="submit" class="space-y-4">
 <div>
 <label class="block text-sm font-medium text-gray-700 dark:text-slate-200 mb-1">Email</label>
 <input
 v-model="form.email"
 type="email"
 autocomplete="email"
 class="w-full border border-gray-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.email }"
 />
 <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
 </div>

 <div>
 <label class="block text-sm font-medium text-gray-700 dark:text-slate-200 mb-1">Password</label>
 <input
 v-model="form.password"
 type="password"
 autocomplete="current-password"
 class="w-full border border-gray-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.password }"
 />
 <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
 </div>

 <button
 type="submit"
 :disabled="form.processing"
 class="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50 transition"
 >
 {{ form.processing ? 'Signing in…' : 'Sign in' }}
 </button>
 </form>

 <p class="text-xs text-gray-400 dark:text-slate-500 mt-6 text-center">
 Demo: owner@demo.test / password
 </p>
 </div>
 </div>
</template>
