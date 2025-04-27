import { defaultTheme } from '@vuepress/theme-default'
import { defineUserConfig } from 'vuepress/cli'
import { viteBundler } from '@vuepress/bundler-vite'
import { googleTagManagerPlugin } from '@vuepress/plugin-google-tag-manager'
import { searchPlugin } from '@vuepress/plugin-search'

export default defineUserConfig({
    lang: 'en-US',

    title: 'PriceBuddy Documentation',
    description: 'A dollar saved is a dollar earned',

    theme: defaultTheme({
        logo: '/logo-light.svg',
        logoDark: '/logo-dark.svg',

        navbar: [
            {link: '/installation', text: 'Docs'},
            {link: '/features', text: 'Features'},
            {link: '/support-project', text: 'Support'},
            {link: 'https://github.com/jez500/pricebuddy', text: 'Github'},
        ],

        sidebar: [
            '/',
            '/installation',
            '/stores',
            '/products',
            '/tags',
            '/settings',
            '/users',
            '/log-messages',
            '/faqs',
        ],
    }),

    bundler: viteBundler(),

    plugins: [
        googleTagManagerPlugin({
            id: 'GTM-KNMBZRWX',
        }),
        searchPlugin({
            //
        })
    ],
})
