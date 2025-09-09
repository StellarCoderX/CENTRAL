 // CENTRAL DE CHECKERS - FUNCIONALIDADES JAVASCRIPT AVANÇADAS

class CheckersApp {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.initAnimations();
        this.setupFormValidation();
        this.initParallaxEffects();
        this.setupToastSystem();
        this.initThemeSystem();
    }

    setupEventListeners() {
        document.addEventListener('DOMContentLoaded', () => {
            this.handleFormSubmissions();
            this.setupToolCardAnimations();
            this.initTypewriterEffect();
            this.setupKeyboardShortcuts();
        });

        window.addEventListener('resize', () => {
            this.handleResize();
        });

        window.addEventListener('scroll', () => {
            this.handleScroll();
        });
    }

    handleFormSubmissions() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    this.showLoadingState(submitBtn);
                }
            });
        });
    }

    showLoadingState(button) {
        button.classList.add('loading');
        button.disabled = true;
        
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
        
        // Simular tempo de processamento mínimo para UX
        setTimeout(() => {
            if (button.classList.contains('loading')) {
                button.innerHTML = originalText;
                button.classList.remove('loading');
                button.disabled = false;
            }
        }, 2000);
    }

    setupToolCardAnimations() {
        const toolCards = document.querySelectorAll('.tool-card');
        toolCards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.classList.add('fade-in');
            
            // Adicionar efeito de hover avançado
            card.addEventListener('mouseenter', () => {
                this.animateCardHover(card, true);
            });
            
            card.addEventListener('mouseleave', () => {
                this.animateCardHover(card, false);
            });
        });
    }

    animateCardHover(card, isHover) {
        const icon = card.querySelector('.tool-icon');
        if (icon) {
            if (isHover) {
                icon.style.transform = 'scale(1.1) rotate(5deg)';
                icon.style.boxShadow = '0 0 30px rgba(0, 255, 136, 0.5)';
            } else {
                icon.style.transform = 'scale(1) rotate(0deg)';
                icon.style.boxShadow = '';
            }
        }
    }

    initTypewriterEffect() {
        const logoText = document.querySelector('.logo-text');
        if (logoText && !window.location.search.includes('page=register')) {
            const text = logoText.textContent;
            logoText.textContent = '';
            let i = 0;
            
            const typeWriter = () => {
                if (i < text.length) {
                    logoText.textContent += text.charAt(i);
                    i++;
                    setTimeout(typeWriter, 100);
                } else {
                    // Adicionar cursor piscante
                    this.addBlinkingCursor(logoText);
                }
            };
            
            setTimeout(typeWriter, 500);
        }
    }

    addBlinkingCursor(element) {
        const cursor = document.createElement('span');
        cursor.textContent = '|';
        cursor.style.animation = 'blink 1s infinite';
        cursor.style.marginLeft = '2px';
        element.appendChild(cursor);
        
        // Adicionar CSS para animação do cursor
        if (!document.querySelector('#cursor-style')) {
            const style = document.createElement('style');
            style.id = 'cursor-style';
            style.textContent = `
                @keyframes blink {
                    0%, 50% { opacity: 1; }
                    51%, 100% { opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }

    initParallaxEffects() {
        document.addEventListener('mousemove', (e) => {
            const shapes = document.querySelectorAll('.shape');
            const x = (e.clientX / window.innerWidth) - 0.5;
            const y = (e.clientY / window.innerHeight) - 0.5;
            
            shapes.forEach((shape, index) => {
                const speed = (index + 1) * 0.5;
                const xPos = x * speed * 10;
                const yPos = y * speed * 10;
                
                shape.style.transform += ` translate(${xPos}px, ${yPos}px)`;
            });
        });
    }

    setupFormValidation() {
        const inputs = document.querySelectorAll('.form-input');
        inputs.forEach(input => {
            input.addEventListener('blur', () => {
                this.validateField(input);
            });

            input.addEventListener('input', () => {
                this.clearFieldError(input);
            });

            // Adicionar validação em tempo real para email
            if (input.type === 'email') {
                input.addEventListener('input', () => {
                    this.validateEmail(input);
                });
            }

            // Adicionar validação de força da senha
            if (input.type === 'password') {
                input.addEventListener('input', () => {
                    this.validatePassword(input);
                });
            }
        });
    }

    validateField(input) {
        const value = input.value.trim();
        const isRequired = input.hasAttribute('required');
        
        if (isRequired && value === '') {
            this.showFieldError(input, 'Este campo é obrigatório');
            return false;
        }
        
        this.showFieldSuccess(input);
        return true;
    }

    validateEmail(input) {
        const email = input.value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (email && !emailRegex.test(email)) {
            this.showFieldError(input, 'Digite um e-mail válido');
            return false;
        }
        
        if (email) {
            this.showFieldSuccess(input);
        }
        return true;
    }

    validatePassword(input) {
        const password = input.value;
        const strength = this.calculatePasswordStrength(password);
        
        this.showPasswordStrength(input, strength);
        return strength.score >= 3;
    }

    calculatePasswordStrength(password) {
        let score = 0;
        let feedback = [];
        
        if (password.length >= 8) score++;
        else feedback.push('Mínimo 8 caracteres');
        
        if (/[a-z]/.test(password)) score++;
        else feedback.push('Letra minúscula');
        
        if (/[A-Z]/.test(password)) score++;
        else feedback.push('Letra maiúscula');
        
        if (/[0-9]/.test(password)) score++;
        else feedback.push('Número');
        
        if (/[^A-Za-z0-9]/.test(password)) score++;
        else feedback.push('Caractere especial');
        
        const levels = ['Muito fraca', 'Fraca', 'Regular', 'Boa', 'Forte'];
        
        return {
            score,
            level: levels[score] || 'Muito fraca',
            feedback
        };
    }

    showPasswordStrength(input, strength) {
        let indicator = input.parentNode.querySelector('.password-strength');
        
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'password-strength';
            indicator.style.cssText = `
                margin-top: 0.5rem;
                font-size: 0.8rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            `;
            input.parentNode.appendChild(indicator);
        }
        
        const colors = ['#ff4757', '#ff6b35', '#ffa502', '#2ed573', '#00ff88'];
        const color = colors[strength.score] || colors[0];
        
        indicator.innerHTML = `
            <div style="
                width: 100px;
                height: 4px;
                background: rgba(255,255,255,0.1);
                border-radius: 2px;
                overflow: hidden;
            ">
                <div style="
                    width: ${(strength.score / 5) * 100}%;
                    height: 100%;
                    background: ${color};
                    transition: all 0.3s ease;
                "></div>
            </div>
            <span style="color: ${color};">${strength.level}</span>
        `;
    }

    showFieldError(input, message) {
        input.style.borderColor = 'var(--error)';
        this.showFieldMessage(input, message, 'error');
    }

    showFieldSuccess(input) {
        input.style.borderColor = 'var(--success)';
        this.removeFieldMessage(input);
    }

    clearFieldError(input) {
        if (input.style.borderColor === 'var(--error)' && input.value.trim() !== '') {
            input.style.borderColor = 'rgba(255, 255, 255, 0.1)';
            this.removeFieldMessage(input);
        }
    }

    showFieldMessage(input, message, type) {
        this.removeFieldMessage(input);
        
        const messageEl = document.createElement('div');
        messageEl.className = `field-message field-message-${type}`;
        messageEl.textContent = message;
        messageEl.style.cssText = `
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--${type});
            display: flex;
            align-items: center;
            gap: 0.25rem;
        `;
        
        const icon = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        messageEl.innerHTML = `<i class="${icon}"></i> ${message}`;
        
        input.parentNode.appendChild(messageEl);
    }

    removeFieldMessage(input) {
        const existingMessage = input.parentNode.querySelector('.field-message');
        if (existingMessage) {
            existingMessage.remove();
        }
    }

    setupToastSystem() {
        // Criar container para toasts
        const toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        `;
        document.body.appendChild(toastContainer);
    }

    showToast(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const colors = {
            success: 'var(--success)',
            error: 'var(--error)',
            warning: 'var(--warning)',
            info: 'var(--info)'
        };
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };
        
        toast.style.cssText = `
            padding: 1rem 1.5rem;
            background: ${colors[type]};
            color: white;
            border-radius: var(--radius-md);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-lg);
            max-width: 300px;
        `;
        
        toast.innerHTML = `<i class="${icons[type]}"></i> ${message}`;
        
        const container = document.getElementById('toast-container');
        container.appendChild(toast);
        
        // Animar entrada
        setTimeout(() => toast.style.transform = 'translateX(0)', 100);
        
        // Animar saída
        setTimeout(() => {
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (container.contains(toast)) {
                    container.removeChild(toast);
                }
            }, 300);
        }, duration);
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + Enter para submeter formulário
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const activeForm = document.querySelector('form');
                if (activeForm) {
                    activeForm.submit();
                }
            }
            
            // Escape para limpar campos
            if (e.key === 'Escape') {
                const activeInput = document.activeElement;
                if (activeInput && activeInput.tagName === 'INPUT') {
                    activeInput.blur();
                }
            }
        });
    }

    initThemeSystem() {
        // Sistema básico de temas (para expansão futura)
        const savedTheme = localStorage.getItem('checkers-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
    }

    handleResize() {
        // Reajustar animações em redimensionamento
        const shapes = document.querySelectorAll('.shape');
        shapes.forEach(shape => {
            shape.style.transform = '';
        });
    }

    handleScroll() {
        // Efeitos de scroll (para páginas longas futuras)
        const scrolled = window.pageYOffset;
        const rate = scrolled * -0.5;
        
        const bgAnimation = document.querySelector('.bg-animation');
        if (bgAnimation) {
            bgAnimation.style.transform = `translateY(${rate}px)`;
        }
    }

    // Métodos utilitários
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    // Animações personalizadas
    animateElement(element, animation, duration = 300) {
        return new Promise(resolve => {
            element.style.animation = `${animation} ${duration}ms ease-out`;
            setTimeout(() => {
                element.style.animation = '';
                resolve();
            }, duration);
        });
    }

    // Sistema de notificações avançado
    notify(title, message, type = 'info') {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, {
                body: message,
                icon: '/favicon.ico'
            });
        } else {
            this.showToast(`${title}: ${message}`, type);
        }
    }

    // Solicitar permissão para notificações
    requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }
}

// Inicializar aplicação
const app = new CheckersApp();

// Exportar para uso global
window.CheckersApp = app;

// Funções globais para compatibilidade
window.showToast = (message, type, duration) => app.showToast(message, type, duration);
window.notify = (title, message, type) => app.notify(title, message, type);
