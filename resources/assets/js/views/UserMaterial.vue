<template>
<div class="row user-materials">
    <div class="col-md-12">
        <b-alert v-if="apiError" show variant="danger">
            API Error: {{ apiError.message }}
        </b-alert>
        <b-alert v-if="serverError" show variant="danger">
            Server Error: {{ serverError }}
        </b-alert>
        <div class="load-container load7 fullscreen" v-if="isProcessing">
            <div class="loader">Loading...</div>
        </div>
        <b-alert :show="actionMessageSeconds"
                 @dismiss-count-down="actionMessageCountdown"
                 variant="info">
            {{ actionMessage }}
        </b-alert>
    </div>

    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h3 class="card-title">
                            Materials Inventory
                        </h3>
                        <p>
                            The following materials are available to you for making recipes.
                            You can add more materials by <a href="/materials">searching the Materials section</a>.
                            Removing a material from your inventory does <em>NOT</em> delete it.
                        </p>
                        <p>
                            Track the amount in stock using Edit. Leave the amount blank to stop tracking,
                            or enter 0 for out of stock. Changing the unit does not convert the amount.
                        </p>
                    </div>
                    <div class="col-md-6">
                        <b-card>
                            <b-media>
                                <a slot="aside" href="/calculator"><i class="fa fa-calculator fa-fw calc-icon-lg"></i>
                                </a>
                                <h5 class="mt-0">Your Calculator Materials</h5>
                                <p>
                                    Note: For simplicity, only materials listed here in your inventory can be used in the
                                    <a href="/calculator">Calculator</a>.
                                </p>
                            </b-media>
                        </b-card>
                    </div>
                </div>
                <div class="table-responsive" v-if="!isProcessing && inventoryMaterials && inventoryMaterials.length > 0">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th scope="col" colspan="2">Material</th>
                            <th scope="col">Author</th>
                            <th scope="col">Amount in Stock</th>
                            <th scope="col">Remove from Inventory</th>
                        </tr>
                    </thead>
                    <tbody>
                    <tr class="col-md-12" v-for="inventory in inventoryMaterials" :key="inventory.id" v-if="'material' in inventory">
                        <td>
                            <router-link :to="{ name: 'material', params: { id: inventory.material.id }}">
                                <img class="img-fluid"
                                     :alt="inventory.material.name"
                                     :src="getImageUrl(inventory.material, 's')"
                                     width="40" height="40">
                            </router-link>
                        </td>
                        <td>
                            <router-link :to="{ name: 'material', params: { id: inventory.material.id }}">
                                <i v-if="inventory.material.isPrivate"
                                   v-b-tooltip.hover title="Private"
                                   class="fa fa-eye-slash"></i>
                                <i v-if="inventory.material.isArchived"
                                   v-b-tooltip.hover title="Archived"
                                   class="fa fa-lock"></i>
                                {{ inventory.material.name }}<em v-if="inventory.material.otherNames">, {{ inventory.material.otherNames }}</em>
                            </router-link>
                        </td>
                        <td>
                            <div class="author">
                                <router-link :to="{ name: 'user', params: { id: getUserSearchParam(inventory.material.createdByUser) }}">
                                    <img v-if="'profile' in inventory.material.createdByUser && 'avatar' in inventory.material.createdByUser.profile"
                                         v-bind:src="inventory.material.createdByUser.profile.avatar"
                                         class="avatar"/>
                                    <span>{{ inventory.material.createdByUser.name }}</span>
                                </router-link>
                            </div>
                        </td>
                        <td class="stock-quantity">
                            <form v-if="editingInventoryId === inventory.id"
                                  @submit.prevent="saveStock(inventory)"
                                  :aria-busy="isSavingStock ? 'true' : 'false'">
                                <div class="form-group mb-2">
                                    <label :for="'stock-amount-' + inventory.id">
                                        Amount <span class="sr-only">of {{ inventory.material.name }} in stock</span>
                                    </label>
                                    <input :id="'stock-amount-' + inventory.id"
                                           v-model="stockDraft.amount"
                                           type="number" min="0" max="9999999999.999" step="0.001"
                                           class="form-control form-control-sm"
                                           :disabled="isSavingStock"
                                           :aria-describedby="'stock-help-' + inventory.id + (stockError ? ' stock-error-' + inventory.id : '')"
                                           :aria-invalid="stockError ? 'true' : 'false'">
                                </div>
                                <div class="form-group mb-2">
                                    <label :for="'stock-unit-' + inventory.id">
                                        Unit <span class="sr-only">for {{ inventory.material.name }}</span>
                                    </label>
                                    <select :id="'stock-unit-' + inventory.id"
                                            v-model="stockDraft.unit"
                                            class="form-control form-control-sm"
                                            :disabled="isSavingStock"
                                            :required="stockDraft.amount !== ''"
                                            :aria-describedby="'stock-help-' + inventory.id + (stockError ? ' stock-error-' + inventory.id : '')">
                                        <option value="">Choose unit</option>
                                        <option value="g">g</option>
                                        <option value="kg">kg</option>
                                        <option value="oz">oz</option>
                                        <option value="lb">lb</option>
                                    </select>
                                </div>
                                <small :id="'stock-help-' + inventory.id" class="form-text text-muted mb-2">
                                    Blank stops tracking; 0 means out of stock. Units do not auto-convert.
                                </small>
                                <p v-if="stockError" :id="'stock-error-' + inventory.id"
                                   class="text-danger mb-2" role="alert">{{ stockError }}</p>
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="isSavingStock">
                                    {{ isSavingStock ? 'Saving...' : 'Save' }}
                                </button>
                                <button type="button" class="btn btn-default btn-sm"
                                        :disabled="isSavingStock" @click="cancelStockEdit">Cancel</button>
                            </form>
                            <div v-else>
                                <span v-if="inventory.stockAmount === null || typeof inventory.stockAmount === 'undefined'"
                                      class="text-muted">Untracked</span>
                                <span v-else>
                                    {{ inventory.stockAmount }}
                                    <span v-if="inventory.stockUnit">{{ inventory.stockUnit }}</span>
                                    <span v-else class="text-muted">(unit not set)</span>
                                    <span v-if="Number(inventory.stockAmount) === 0">— Out of stock</span>
                                </span>
                                <button type="button" class="btn btn-default btn-sm ml-2"
                                        :disabled="editingInventoryId !== null || isSavingStock"
                                        :aria-label="'Edit amount in stock for ' + inventory.material.name"
                                        @click="editStock(inventory)">Edit</button>
                            </div>
                        </td>
                        <td>
                            <b-button v-b-tooltip.hover title="Remove from Inventory"
                                   @click="removeMaterialRequest(inventory.id)"
                                   :disabled="editingInventoryId !== null || isSavingStock"
                                   :aria-label="'Remove ' + inventory.material.name + ' from inventory'"
                                   class="btn btn-icon btn-default"><i class="fa fa-times"></i></b-button>
                        </td>
                    </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

    </div>

</div>
</template>


<script>
  export default {
    props: {
    },
    components: {
    },
    data() {
      return {
        user: null,
        inventoryMaterials: null,
        editingInventoryId: null,
        stockDraft: { amount: '', unit: '' },
        stockError: null,
        isSavingStock: false,
        errors: [],
        apiError: null,
        serverError: null,
        isProcessing: false,
        actionMessage: null,
        actionMessageSeconds: 0
      }
    },
    created() {
      if (this.$auth.check()) {
        this.user = this.$auth.user()
      }
      this.getInventoryMaterials()
    },
    computed: {
      isLoaded: function () {
        if (this.$auth.check()) {
          return true;
        }
        return false;
      }
    },
    methods: {
      editStock(inventory) {
        if (this.isSavingStock || this.editingInventoryId !== null || this.isProcessing) { return }
        this.editingInventoryId = inventory.id
        this.stockDraft = {
          amount: inventory.stockAmount === null || typeof inventory.stockAmount === 'undefined'
            ? '' : String(inventory.stockAmount),
          unit: inventory.stockUnit || ''
        }
        this.stockError = null
      },

      cancelStockEdit() {
        if (this.isSavingStock) { return }
        this.editingInventoryId = null
        this.stockDraft = { amount: '', unit: '' }
        this.stockError = null
      },

      saveStock(inventory) {
        if (this.isSavingStock || this.isProcessing || this.editingInventoryId !== inventory.id) { return }
        const amount = String(this.stockDraft.amount).trim()
        const unit = this.stockDraft.unit
        this.stockError = null
        if (amount !== '' && (!/^(?:\d+(?:\.\d{0,3})?|\.\d{1,3})$/.test(amount) ||
          Number(amount) > 9999999999.999)) {
          this.stockError = 'Enter an amount from 0 to 9999999999.999 with up to 3 decimal places, or leave it blank to stop tracking.'
          return
        }
        if (amount !== '' && ['g', 'kg', 'oz', 'lb'].indexOf(unit) === -1) {
          this.stockError = 'Choose g, kg, oz, or lb for this amount. Changing the unit does not convert it.'
          return
        }
        this.isSavingStock = true
        Vue.axios.patch(Vue.axios.defaults.baseURL + '/usermaterials/' + inventory.id, {
          stock_amount: amount === '' ? null : amount,
          stock_unit: amount === '' ? null : unit
        }).then(response => {
          if (response.data.error || !response.data.data) {
            this.stockError = 'The amount could not be saved. Your changes are still here; try Save again.'
          } else {
            this.$set(inventory, 'stockAmount', response.data.data.stockAmount)
            this.$set(inventory, 'stockUnit', response.data.data.stockUnit)
            this.isSavingStock = false
            this.cancelStockEdit()
            this.actionMessage = 'Amount in stock saved.'
            this.actionMessageSeconds = 5
          }
          this.isSavingStock = false
        }).catch(error => {
          const status = error.response ? error.response.status : null
          if (status === 422) {
            this.stockError = 'Check the amount (0 to 9999999999.999, up to 3 decimal places) and choose g, kg, oz, or lb. Leave the amount blank to stop tracking, then Save again.'
          } else if (status === 401 || status === 403) {
            this.stockError = 'You do not have permission to save this inventory. Check that you are signed in to the correct account before trying again.'
          } else if (status === 404) {
            this.stockError = 'This inventory entry is no longer available. Copy your changes before reloading the page.'
          } else {
            this.stockError = 'The amount could not be saved. Check your connection and try Save again; your changes are still here.'
          }
          this.isSavingStock = false
        })
      },


      getInventoryMaterials: function () {
        if (this.isLoaded) {
          this.isProcessing = true
          this.serverError = null
          Vue.axios.get(Vue.axios.defaults.baseURL + '/usermaterials')
            .then((response) => {
            console.log('got response:')
            console.log(response)
            if (response.data.error) {
              // error
              this.apiError = response.data.error
              console.log(this.apiError)
            } else {
              // got results
              if (response.data.data) {
                this.inventoryMaterials = response.data.data
              }
            }
            this.isProcessing = false
          })
          .catch(response => {
            this.serverError = response
            this.isProcessing = false
            console.log('Get user materials ERROR')
            console.log(response.data)
          })
        }
      },

      removeMaterialRequest(inventoryId) {
        if (!inventoryId || this.isSavingStock || this.editingInventoryId !== null || this.isProcessing) {
          return
        }

        console.log('remove inventory id: ' + inventoryId)

        this.isProcessing = true
        Vue.axios.delete(Vue.axios.defaults.baseURL + '/usermaterials/' + inventoryId)
          .then((response) => {
          if (response.data.error) {
            this.apiError = response.data.error
            console.log(this.apiError)
            this.isProcessing = false
          } else {
            this.actionMessage = 'Removed material from inventory.'
            this.actionMessageSeconds = 5
            this.getInventoryMaterials()
            console.log('refresh user materials')
            // Refresh user inventory materials
            this.$auth.fetch({
              success(res) {
                console.log('success fetching user');
                console.log(this.$auth.user())
                console.log('user id: ' + this.$auth.user().id)
                // Refresh the recipe
                this.fetchRecipe()
              },
              error() {
                console.log('error fetching user');
              }
            })
          }
        })
        .catch(response => {
          this.serverError = response
          console.log(response)
          this.isProcessing = false
        })
      },

      getUserSearchParam: function (user) {
        if (!user) { return }
        if ('profile' in user && 'username' in user.profile && user.profile.username) {
          return user.profile.username
        }
        return user.id
      },

      getImageBin: function(id) {
        id = '' + id;
        return id.substr(id.length - 2);
      },

      hasThumbnail: function(recipe) {
        if (recipe.hasOwnProperty('thumbnail')
          && recipe.thumbnail.hasOwnProperty('filename')
          && recipe.thumbnail.filename) {
          return true;
        }
        return false;
      },

      getImageUrl: function(recipe, size) {
        if (this.hasThumbnail(recipe)) {
          var bin = this.getImageBin(recipe.id);
          return '/storage/uploads/recipes/' + bin + '/' + size + '_' + recipe.thumbnail.filename;
        }
        return '/img/recipes/black.png';
      },

      actionMessageCountdown(seconds) {
        this.actionMessageSeconds = seconds
      }

    }
  }
</script>

<style>
    .user-materials {
        padding-top: 15px;
    }

    .user-materials .stock-quantity {
        min-width: 240px;
    }

    .calc-icon-lg {
        font-size: 42px;
    }
</style>